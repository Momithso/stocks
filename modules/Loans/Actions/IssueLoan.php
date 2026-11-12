<?php

namespace Modules\Loans\Actions;

use App\Actions\Inventory\MoveItem;
use App\Enums\ItemKind;
use App\Enums\MovementType;
use App\Models\Item;
use App\Models\ItemCondition;
use App\Models\ItemPhoto;
use App\Models\User;
use App\Support\Inventory\CodeLookup;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Loans\Enums\DocumentType;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Enums\ReturnState;
use Modules\Loans\Enums\SignatureRole;
use Modules\Loans\Models\Contact;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanDocument;
use Modules\Loans\Models\LoanItem;
use Modules\Loans\Support\Availability;
use Modules\Loans\Support\LoanDocuments;
use Modules\Loans\Support\SignatureInput;
use Modules\Loans\Support\Snapshot;

/**
 * The issue of a confirmed loan ("Warenausgang"), step by step. The state is kept on the server, so
 * it can be interrupted and continued on another device:
 *
 * 1. prepare(): the contents of every box become rows below it (refreshed until the end),
 * 2. check() / uncheck(): every scan ticks a row off, by hand as well; fewer pieces of a bulk
 *    item than booked split the position, the rest stays a position of its own,
 * 3. defect(): a defect found now is documented (and may keep the row back),
 * 4. complete(): signatures (or "auf Papier"), the contract and the material list are frozen with
 *    their SHA-256 hash, the ticked items leave their place ("ausgegeben") and the time runs.
 *
 * What is not ticked off is the rest (partial issue, no defect): it stays booked and leaves later
 * with the same steps and a material list of its own ("Nachtrag"; the contract is signed only the
 * first time), or it is dropped from the loan (drop()).
 */
class IssueLoan
{
    public function __construct(
        private readonly CodeLookup $codes,
        private readonly MoveItem $moveItem,
        private readonly Availability $availability,
        private readonly LoanDocuments $documents,
        private readonly ConfirmLoan $confirmLoan,
        private readonly RecordDefect $recordDefect,
        private readonly ReturnLoan $returnLoan,
    ) {}

    /**
     * Bring the rows of the box contents in line with the boxes: new contents get a row, removed
     * ones lose theirs. Ticks of rows that stay are kept.
     *
     * @throws ValidationException
     */
    public function prepare(Loan $loan): void
    {
        $this->ensureIssuable($loan, leaving: false);

        DB::transaction(function () use ($loan) {
            // the contents of a box that left are frozen
            foreach ($loan->positions()->whereNull('issued_at')->with('item')->get() as $position) {
                $this->syncContents($loan, $position, $position->item, 0);
            }
        });
    }

    /**
     * Tick a row off by a scanned code (or by hand with the id of the row). A code that is not in
     * the loan returns the item, so the page can offer to add it.
     *
     * @return array{row: LoanItem|null, foreign: Item|null}
     *
     * @throws ValidationException
     */
    public function check(User $actor, Loan $loan, ?string $code, ?int $rowId, ?int $quantity = null): array
    {
        $this->ensureIssuable($loan);

        $row = $rowId ? $loan->loanItems()->whereNull('issued_at')->findOrFail($rowId) : $this->rowOf($loan, (string) $code);
        if ($row === null) {
            $item = $this->codes->find((string) $code);
            if (! $item instanceof Item) {
                throw ValidationException::withMessages(['code' => $this->codes->notFoundMessage((string) $code)]);
            }

            return ['row' => null, 'foreign' => $item];
        }

        // a box that came back unchecked counts only when its contents are ticked one by one
        if ($row->isUncheckedBox() && $row->children()->exists()) {
            throw ValidationException::withMessages(['code' => "{$row->item->number} kam ungeprüft zurück. Hake den Inhalt einzeln ab, dann ist die Box mit abgehakt."]);
        }

        if ($quantity !== null && $row->isBulk() && $row->parent_id === null) {
            if ($quantity < 1 || $quantity > $row->item->quantity) {
                throw ValidationException::withMessages(['quantity' => "Die Menge muss zwischen 1 und {$row->item->quantity} liegen."]);
            }
            Availability::exclusively(fn () => DB::transaction(function () use ($loan, $row, $quantity) {
                $check = $this->availability->check($row->item, $quantity, $loan->pickup_at, $loan->return_at, $loan);
                if ($check['blocked']) {
                    throw ValidationException::withMessages(['quantity' => $this->availability->message($row->item, $check)]);
                }
                // a changed count: the rest split off before joins again first
                $this->joinRests($loan, $row);
                // fewer pieces than booked: the rest stays booked as a position of its own
                if ($quantity < $row->quantity) {
                    $loan->loanItems()->create(['item_id' => $row->item_id, 'quantity' => $row->quantity - $quantity]);
                }
                $row->quantity = $quantity;
                $row->save();
            }));
        }
        $this->tick($row, ['checked_at' => now(), 'checked_by' => $actor->id, 'checked_by_scan' => $rowId === null]);

        return ['row' => $row, 'foreign' => null];
    }

    /**
     * Take the tick of a row back.
     */
    public function uncheck(Loan $loan, LoanItem $row): void
    {
        $this->ensureIssuable($loan, leaving: false);
        if ($row->issued_at !== null) {
            throw ValidationException::withMessages(['loan' => 'Das ist schon ausgegeben.']);
        }

        DB::transaction(function () use ($loan, $row) {
            $this->tick($row, ['checked_at' => null, 'checked_by' => null, 'checked_by_scan' => false]);
            // a split bulk position becomes one again
            if ($row->parent_id === null && $row->isBulk()) {
                $this->joinRests($loan, $row);
            }
        });
    }

    /**
     * Join the rests split off a bulk position (not ticked, not left) into it again.
     */
    private function joinRests(Loan $loan, LoanItem $row): void
    {
        $rests = $loan->positions()->whereKeyNot($row->id)->where('item_id', $row->item_id)->whereNull('issued_at')->whereNull('checked_at')->get();
        if ($rests->isNotEmpty()) {
            $row->forceFill(['quantity' => $row->quantity + $rests->sum('quantity')])->save();
            LoanItem::whereIn('id', $rests->modelKeys())->delete();
        }
    }

    /**
     * Document a defect found at the issue (damaged, dirty, incomplete) with note and photos.
     * "Beschädigt" changes the condition. Kept back, the row is no longer ticked and becomes rest.
     *
     * @param  list<UploadedFile>  $photos
     *
     * @throws ValidationException
     */
    public function defect(User $actor, Loan $loan, LoanItem $row, ReturnState $state, string $note, ?ItemCondition $condition, array $photos, bool $keepBack): void
    {
        $this->ensureIssuable($loan, leaving: false);
        if ($row->issued_at !== null || $state === ReturnState::Ok || $state === ReturnState::Missing) {
            throw ValidationException::withMessages(['state' => 'Bei der Ausgabe gibt es beschädigt, verschmutzt oder unvollständig.']);
        }

        DB::transaction(function () use ($actor, $loan, $row, $state, $note, $condition, $photos, $keepBack) {
            $this->recordDefect->handle($actor, $loan, $row->item, $state, $note, $condition, $photos, 'bei der Ausgabe von');
            $row->forceFill(['issue_state' => $state, 'issue_note' => $note])->save();
            // only a position stays back; the contents of a box go with their box
            if ($keepBack && $row->parent_id === null && $row->checked_at !== null) {
                $this->uncheck($loan, $row);
            }
        });
    }

    /**
     * Take the defect mark of a row back. A changed condition stays changed.
     */
    public function resetDefect(Loan $loan, LoanItem $row): void
    {
        $this->ensureIssuable($loan, leaving: false);
        if ($row->issued_at === null) {
            $row->forceFill(['issue_state' => null, 'issue_note' => null])->save();
        }
    }

    /**
     * Drop a rest from the loan: a position that did not leave and will not leave later. A loan
     * that is out and has everything else back is closed.
     *
     * @throws ValidationException
     */
    public function drop(Loan $loan, LoanItem $position): void
    {
        $this->ensureIssuable($loan, leaving: false);
        if ($position->parent_id !== null || $position->issued_at !== null) {
            throw ValidationException::withMessages(['loan' => 'Streichen lässt sich nur eine Position, die noch nicht ausgegeben ist.']);
        }

        DB::transaction(function () use ($loan, $position) {
            $loan->lockForTask();
            // a confirmed loan without positions would hold nothing: that is a cancellation
            if ($loan->status === LoanStatus::Confirmed && ! $loan->positions()->whereKeyNot($position->id)->exists()) {
                throw ValidationException::withMessages(['loan' => 'Das ist die letzte Position. Storniere die Buchung, wenn nichts mitgehen soll.']);
            }
            $position->delete();
            $this->returnLoan->finishIfBack($loan->refresh());
        });
    }

    /**
     * Tick a row (or take the tick back) together with the rows it belongs to: a box takes its
     * contents along, and a box whose contents are all ticked is ticked itself. A box that came back
     * unchecked before does not tick its contents, they are ticked one by one.
     *
     * @param  array{checked_at: mixed, checked_by: int|null, checked_by_scan: bool}  $fields
     */
    private function tick(LoanItem $row, array $fields): void
    {
        $row->forceFill($fields)->save();
        $ticking = $fields['checked_at'] !== null;

        if (! $ticking || ! $row->isUncheckedBox()) {
            LoanItem::whereIn('id', $row->descendantIds())->update($fields);
        }

        for ($parent = $row->parent; $parent !== null; $parent = $parent->parent) {
            $complete = ! $parent->children()->whereNull('checked_at')->exists();
            if ($ticking && ! $complete) {
                break;
            }
            // a box with a row taken back is no longer complete
            $parent->forceFill($ticking ? $fields : ['checked_at' => null, 'checked_by' => null, 'checked_by_scan' => false])->save();
        }
    }

    /**
     * Someone else picks up: the responsible person changes before the signature. The new
     * contact must be complete.
     *
     * @throws ValidationException
     */
    public function changeResponsible(Loan $loan, Contact $contact): void
    {
        // the contract names the person; a supplement is signed by the same one
        $this->ensureConfirmed($loan);
        if ($missing = $contact->missingFields()) {
            throw ValidationException::withMessages(['responsible_contact_id' => "Bei {$contact->name} fehlen noch: ".implode(', ', $missing).'.']);
        }

        $loan->update(['responsible_contact_id' => $contact->id]);
    }

    /**
     * Finish the issue.
     *
     * @param  array<string, string|null>  $signatures  png data uris by role (responsible, issuer)
     * @param  bool  $onPaper  both signed a printout instead
     * @param  UploadedFile|null  $scan  photo or scan of the signed paper
     *
     * @throws ValidationException
     */
    public function complete(User $issuer, Loan $loan, array $signatures, bool $onPaper, ?UploadedFile $scan, ?string $device): void
    {
        $this->prepare($loan);
        if (! $loan->isInternal() && ! $onPaper) {
            foreach ([SignatureRole::Responsible, SignatureRole::Issuer] as $role) {
                if (! SignatureInput::isPng($signatures[$role->value] ?? null)) {
                    throw ValidationException::withMessages(["signatures.{$role->value}" => 'Die Unterschrift fehlt.']);
                }
            }
        }

        // one at a time (an early pickup reserves a new time), and the loan locked, so a second
        // tap or a repeated request finds it issued and does nothing
        Availability::exclusively(fn () => DB::transaction(function () use ($issuer, $loan, $signatures, $onPaper, $scan, $device) {
            $loan->lockForTask();
            $this->ensureIssuable($loan);
            $first = $loan->status === LoanStatus::Confirmed;

            $leaving = $loan->positions()->whereNull('issued_at')->whereNotNull('checked_at')->with('item')->get();
            if ($leaving->isEmpty()) {
                throw ValidationException::withMessages(['loan' => 'Noch nichts abgehakt.']);
            }
            $this->ensureComplete($leaving);
            if ($first && ($problems = $this->confirmLoan->responsibleProblems($loan))) {
                throw ValidationException::withMessages(['loan' => implode(' ', $problems)]);
            }

            // picked up early: the time starts now, if nothing collides
            if ($first && $loan->pickup_at->isFuture()) {
                if ($problems = $this->availability->problems($loan, now(), $loan->return_at)) {
                    throw ValidationException::withMessages(['loan' => 'Eine Ausgabe vor dem Abholtermin geht nicht: '.implode(' ', $problems)]);
                }
                $loan->pickup_at = now();
            }

            $rowIds = $leaving->flatMap(fn (LoanItem $position) => [$position->id, ...$position->descendantIds()])->all();
            foreach (LoanItem::whereIn('id', $rowIds)->with('item.condition')->get() as $row) {
                $row->condition_at_issue = $row->item->condition->name;
                $row->save();
            }

            $snapshot = $this->documents->issueSnapshot($loan, $issuer, $leaving, ! $first);
            $hash = Snapshot::hash($snapshot);
            // the contract only the first time; a supplement gets a material list of its own
            $types = $loan->isInternal() || ! $first ? [DocumentType::MaterialList] : [DocumentType::Contract, DocumentType::MaterialList];
            $list = null;
            foreach ($types as $type) {
                $document = $loan->documents()->create(['type' => $type, 'snapshot' => $snapshot, 'hash' => $hash, 'created_by' => $issuer->id]);
                $list = $type === DocumentType::MaterialList ? $document : $list;
                if (! $loan->isInternal()) {
                    SignatureInput::store($loan, $document, [
                        SignatureRole::Responsible->value => ['name' => $loan->responsible->name, 'image' => $signatures[SignatureRole::Responsible->value] ?? null, 'user' => null],
                        SignatureRole::Issuer->value => ['name' => $issuer->name, 'image' => $signatures[SignatureRole::Issuer->value] ?? null, 'user' => $issuer],
                    ], $onPaper, $device);
                }
            }
            if ($scan) {
                $this->storeScan($issuer, $loan, $scan);
            }

            // the items leave their place; the contents of a box stay in it and travel along
            foreach ($leaving as $position) {
                $left = $this->moveItem->takeAway($issuer, $position->item, MovementType::Issue, $position->item->kind === ItemKind::Bulk ? $position->quantity : null, "Ausgegeben mit {$loan->number}.");
                if ($left->isNot($position->item)) {
                    $position->forceFill(['issued_item_id' => $left->id])->save();
                }
            }
            LoanItem::whereIn('id', $rowIds)->update(['issued_at' => now(), 'issue_document_id' => $list->id]);

            if ($first) {
                $loan->forceFill([
                    'status' => LoanStatus::Issued,
                    'issued_at' => now(),
                    'issuer_id' => $issuer->id,
                    'contract_text' => $snapshot['contract'],
                ]);
            }
            $loan->save();
        }));
    }

    /**
     * Check that every leaving box leaves with its contents ticked off (e.g. contents put into the
     * box after it was ticked, or a box that came back unchecked).
     *
     * @param  Collection<int, LoanItem>  $leaving
     *
     * @throws ValidationException
     */
    private function ensureComplete(Collection $leaving): void
    {
        foreach ($leaving as $position) {
            $open = LoanItem::whereIn('id', $position->descendantIds())->whereNull('checked_at')->with('item')->get();
            if ($open->isNotEmpty()) {
                throw ValidationException::withMessages(['loan' => "In {$position->item->number} ist noch nicht alles abgehakt: ".$open->map(fn (LoanItem $row) => $row->item->number)->implode(', ').'.']);
            }
        }
    }

    /**
     * Store the photo or scan of a paper signed on paper (S3).
     */
    public function storeScan(User $actor, Loan $loan, UploadedFile $scan): LoanDocument
    {
        $path = $scan->store("loans/{$loan->id}", ItemPhoto::DISK);
        if ($path === false) {
            throw ValidationException::withMessages(['scan' => 'Das Blatt konnte nicht gespeichert werden, der Speicher ist gerade nicht erreichbar.']);
        }

        return $loan->documents()->create(['type' => DocumentType::PaperScan, 'path' => $path, 'created_by' => $actor->id]);
    }

    /**
     * Row of the loan with the number of a code: a position or a row of a box.
     */
    private function rowOf(Loan $loan, string $code): ?LoanItem
    {
        $item = $this->codes->find($code);

        // a split bulk item: the part that is not ticked yet first
        return $item instanceof Item
            ? $loan->loanItems()->where('item_id', $item->id)->whereNull('issued_at')->orderByRaw('checked_at is not null')->first()
            : null;
    }

    /**
     * Rows of the contents of a box, boxes in it included.
     */
    private function syncContents(Loan $loan, LoanItem $parent, Item $box, int $depth): void
    {
        if (! $box->is_container || $depth > 20) {
            return;
        }

        $contents = $box->contents()->active()->get();
        $parent->children()->whereNotIn('item_id', $contents->modelKeys())->delete();
        foreach ($contents as $item) {
            $row = $parent->children()->firstOrCreate(['item_id' => $item->id], ['loan_id' => $loan->id, 'quantity' => $item->quantity]);
            $row->update(['quantity' => $item->quantity]);
            $this->syncContents($loan, $row, $item, $depth + 1);
        }
    }

    /**
     * Check if something of the loan is left to issue: a confirmed loan, or an issued one with a
     * rest of a partial issue.
     */
    public static function hasRest(Loan $loan): bool
    {
        return $loan->status === LoanStatus::Issued && $loan->positions()->whereNull('issued_at')->exists();
    }

    /**
     * @throws ValidationException
     */
    private function ensureConfirmed(Loan $loan): void
    {
        if ($loan->status !== LoanStatus::Confirmed) {
            throw ValidationException::withMessages(['loan' => 'Das geht nur vor der ersten Ausgabe.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensureIssuable(Loan $loan, bool $leaving = true): void
    {
        if ($loan->status !== LoanStatus::Confirmed && ! self::hasRest($loan)) {
            throw ValidationException::withMessages(['loan' => 'Ausgeben lässt sich nur eine bestätigte Buchung oder der Rest einer Teilausgabe.']);
        }
        // after the return time nothing is held any more: first a new time, or drop the rest
        if ($leaving && $loan->return_at->isPast()) {
            throw ValidationException::withMessages(['loan' => $loan->status === LoanStatus::Confirmed
                ? 'Der Rückgabetermin ist vorbei, die Buchung wurde nicht abgeholt. Ändere erst den Zeitraum oder storniere sie.'
                : 'Der Rückgabetermin ist vorbei, der Rest wurde nicht abgeholt. Verlängere die Buchung oder streiche den Rest.']);
        }
    }

    /**
     * Check if the rest of a partial issue missed its time: it holds nothing any more and waits to
     * be dropped (or the loan to be extended).
     */
    public static function restExpired(Loan $loan): bool
    {
        return self::hasRest($loan) && $loan->return_at->isPast();
    }
}
