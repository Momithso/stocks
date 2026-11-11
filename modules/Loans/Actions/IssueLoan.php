<?php

namespace Modules\Loans\Actions;

use App\Actions\Inventory\MoveItem;
use App\Enums\ItemKind;
use App\Enums\MovementType;
use App\Models\Item;
use App\Models\ItemPhoto;
use App\Models\User;
use App\Support\Inventory\CodeLookup;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Loans\Enums\DocumentType;
use Modules\Loans\Enums\LoanStatus;
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
 * 2. check() / uncheck(): every scan ticks a row off, by hand as well,
 * 3. complete(): signatures (or "auf Papier"), the contract and the material list are frozen with
 *    their SHA-256 hash, the items leave their place ("ausgegeben") and the time runs.
 */
class IssueLoan
{
    public function __construct(
        private readonly CodeLookup $codes,
        private readonly MoveItem $moveItem,
        private readonly Availability $availability,
        private readonly LoanDocuments $documents,
        private readonly ConfirmLoan $confirmLoan,
    ) {}

    /**
     * Bring the rows of the box contents in line with the boxes: new contents get a row, removed
     * ones lose theirs. Ticks of rows that stay are kept.
     *
     * @throws ValidationException
     */
    public function prepare(Loan $loan): void
    {
        $this->ensureConfirmed($loan);

        DB::transaction(function () use ($loan) {
            foreach ($loan->positions()->with('item')->get() as $position) {
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
        $this->ensureConfirmed($loan);

        $row = $rowId ? $loan->loanItems()->findOrFail($rowId) : $this->rowOf($loan, (string) $code);
        if ($row === null) {
            $item = $this->codes->find((string) $code);
            if (! $item instanceof Item) {
                throw ValidationException::withMessages(['code' => $this->codes->notFoundMessage((string) $code)]);
            }

            return ['row' => null, 'foreign' => $item];
        }

        if ($quantity !== null && $row->isBulk() && $row->parent_id === null) {
            if ($quantity < 1 || $quantity > $row->item->quantity) {
                throw ValidationException::withMessages(['quantity' => "Die Menge muss zwischen 1 und {$row->item->quantity} liegen."]);
            }
            $check = $this->availability->check($row->item, $quantity, $loan->pickup_at, $loan->return_at, $loan);
            if ($check['blocked']) {
                throw ValidationException::withMessages(['quantity' => $this->availability->message($row->item, $check)]);
            }
            $row->quantity = $quantity;
        }
        $this->tick($row, ['checked_at' => now(), 'checked_by' => $actor->id, 'checked_by_scan' => $rowId === null]);

        return ['row' => $row, 'foreign' => null];
    }

    /**
     * Take the tick of a row back.
     */
    public function uncheck(Loan $loan, LoanItem $row): void
    {
        $this->ensureConfirmed($loan);
        $this->tick($row, ['checked_at' => null, 'checked_by' => null, 'checked_by_scan' => false]);
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
        $this->ensureConfirmed($loan);
        $this->prepare($loan);

        $open = $loan->positions()->whereNull('checked_at')->with('item')->get();
        if ($open->isNotEmpty()) {
            throw ValidationException::withMessages(['loan' => 'Noch nicht abgehakt: '.$open->map(fn (LoanItem $row) => "{$row->item->number} {$row->item->name}")->implode(', ').'. Abhaken oder aus der Buchung nehmen.']);
        }
        if ($problems = $this->confirmLoan->responsibleProblems($loan)) {
            throw ValidationException::withMessages(['loan' => implode(' ', $problems)]);
        }
        if (! $loan->isInternal() && ! $onPaper) {
            foreach ([SignatureRole::Responsible, SignatureRole::Issuer] as $role) {
                if (! SignatureInput::isPng($signatures[$role->value] ?? null)) {
                    throw ValidationException::withMessages(["signatures.{$role->value}" => 'Die Unterschrift fehlt.']);
                }
            }
        }

        DB::transaction(function () use ($issuer, $loan, $signatures, $onPaper, $scan, $device) {
            // picked up early: the time starts now, if nothing collides
            if ($loan->pickup_at->isFuture()) {
                if ($problems = $this->availability->problems($loan, now(), $loan->return_at)) {
                    throw ValidationException::withMessages(['loan' => 'Eine Ausgabe vor dem Abholtermin geht nicht: '.implode(' ', $problems)]);
                }
                $loan->pickup_at = now();
            }

            foreach ($loan->loanItems()->with('item.condition')->get() as $row) {
                $row->condition_at_issue = $row->item->condition->name;
                $row->save();
            }

            $snapshot = $this->documents->issueSnapshot($loan, $issuer);
            $hash = Snapshot::hash($snapshot);
            $types = $loan->isInternal() ? [DocumentType::MaterialList] : [DocumentType::Contract, DocumentType::MaterialList];
            foreach ($types as $type) {
                $document = $loan->documents()->create(['type' => $type, 'snapshot' => $snapshot, 'hash' => $hash, 'created_by' => $issuer->id]);
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
            foreach ($loan->positions()->with('item')->get() as $position) {
                $left = $this->moveItem->takeAway($issuer, $position->item, MovementType::Issue, $position->item->kind === ItemKind::Bulk ? $position->quantity : null, "Ausgegeben mit {$loan->number}.");
                if ($left->isNot($position->item)) {
                    $position->forceFill(['issued_item_id' => $left->id])->save();
                }
            }

            $loan->forceFill([
                'status' => LoanStatus::Issued,
                'issued_at' => now(),
                'issuer_id' => $issuer->id,
                'contract_text' => $snapshot['contract'],
            ])->save();
        });
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

        return $item instanceof Item ? $loan->loanItems()->where('item_id', $item->id)->first() : null;
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
     * @throws ValidationException
     */
    private function ensureConfirmed(Loan $loan): void
    {
        if ($loan->status !== LoanStatus::Confirmed) {
            throw ValidationException::withMessages(['loan' => 'Ausgeben lässt sich nur eine bestätigte Buchung.']);
        }
    }
}
