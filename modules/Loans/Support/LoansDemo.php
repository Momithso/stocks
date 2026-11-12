<?php

namespace Modules\Loans\Support;

use App\Models\Item;
use App\Models\ItemCondition;
use App\Models\User;
use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoPart;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Loans\Actions\ChangePositions;
use Modules\Loans\Actions\CheckBox;
use Modules\Loans\Actions\ConfirmLoan;
use Modules\Loans\Actions\IssueLoan;
use Modules\Loans\Actions\ReturnLoan;
use Modules\Loans\Actions\SaveLoan;
use Modules\Loans\Actions\StowItem;
use Modules\Loans\Enums\ReturnState;
use Modules\Loans\Models\Contact;
use Modules\Loans\Models\Council;
use Modules\Loans\Models\Event;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanExtensionRequest;
use Modules\Loans\Models\LoanItem;
use Modules\Loans\Models\LoanMessage;

/**
 * Demo data of the loan module: 80 contacts, 12 events and 120 loans in every status, built from
 * building blocks with the seed of the generator (documentation/DEMO.md).
 *
 * Every loan runs through the actions at its own time: the clock is set to the pickup for the
 * issue and to the return for the return, so documents, history and audit carry real dates. Past
 * loans may share items (they hold nothing any more); loans that hold items now or later get items
 * of their own.
 *
 * The borrower page (Phase 5) gets chats, ticks of the checklist, extension requests, a log of
 * messages and a blocked link; the generator itself sends nothing.
 */
class LoansDemo implements DemoPart
{
    private const FIRST_NAMES = ['Anna', 'Ben', 'Clara', 'David', 'Emma', 'Felix', 'Greta', 'Hannes', 'Ida', 'Jonas', 'Kira', 'Leon', 'Mia', 'Noah', 'Olivia', 'Paul', 'Ronja', 'Samuel', 'Tara', 'Yusuf', 'Zoe', 'Lukas', 'Selin', 'Mats'];

    private const LAST_NAMES = ['Becker', 'Schmitz', 'Wagner', 'Hoffmann', 'Krause', 'Yilmaz', 'Neumann', 'Schulz', 'Weber', 'Fischer', 'Kaya', 'Lange', 'Braun', 'Jansen', 'Peters', 'Klein', 'Wolf', 'Nowak'];

    private const STREETS = ['Eupener Straße', 'Bayernallee', 'Goethestraße', 'Pontstraße', 'Jakobstraße', 'Lothringerstraße', 'Boxgraben', 'Roermonder Straße'];

    private const PURPOSES = ['Erstiparty', 'Grillabend', 'Spieleabend', 'Hörsaalkino', 'Infostand', 'Weihnachtsfeier', 'Sommerfest', 'Fachschaftsfahrt', 'Workshop', 'Absolventenfeier', 'Lerngruppe', 'Konzert im Foyer', 'Tag der offenen Tür'];

    private const EVENTS = ['Erstiwoche', 'Sommerfest', 'Weihnachtsfeier', 'Spieleabend', 'Grillfest', 'Hörsaalkino', 'Fachschaftsfahrt', 'Absolventenfeier', 'Tag der offenen Tür', 'Lange Nacht der Wissenschaft', 'Kneipentour', 'Filmabend'];

    /**
     * Loans per status (120 in total).
     */
    private const COUNTS = ['closed' => 40, 'defects' => 6, 'cancelled' => 6, 'unchecked' => 4, 'issued' => 14, 'confirmed' => 30, 'draft' => 20];

    private DemoContext $demo;

    private User $member;

    /**
     * @var list<Contact>
     */
    private array $contacts = [];

    /**
     * @var list<Event>
     */
    private array $events = [];

    /**
     * Signature image for the demo loans (a drawn line).
     */
    private string $signature;

    /**
     * Loans of the test sheet whose borrower pages get a chat (issued and overdue).
     */
    private ?Loan $pageLoan = null;

    private ?Loan $overdueLoan = null;

    public function __construct(
        private readonly SaveLoan $saveLoan,
        private readonly ChangePositions $positions,
        private readonly ConfirmLoan $confirmLoan,
        private readonly IssueLoan $issueLoan,
        private readonly ReturnLoan $returnLoan,
        private readonly StowItem $stowItem,
        private readonly CheckBox $checkBox,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function run(DemoContext $demo): void
    {
        $this->demo = $demo;
        $this->member = User::where('email', 'demo2@example.com')->first() ?? $demo->actor;
        $this->signature = $this->signatureImage();

        try {
            $this->createContacts();
            $this->createEvents();
            $this->createPastLoans();
            $this->createCurrentLoans();
            $this->createPageData();
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * 80 contacts: most complete, some without phone or address; private or of a council.
     */
    private function createContacts(): void
    {
        $councils = Council::orderBy('number')->get()->all();
        for ($index = 0; $index < 80; $index++) {
            $first = $this->demo->pick(self::FIRST_NAMES);
            $last = $this->demo->pick(self::LAST_NAMES);
            $incomplete = $index % 7 === 3;
            $this->contacts[] = Contact::create([
                'name' => "{$first} {$last}",
                'email' => mb_strtolower("{$first}.{$last}{$index}@example.com"),
                'phone' => $incomplete ? null : '0241 '.$this->demo->int(100000, 999999),
                'street' => $incomplete && $index % 2 ? null : $this->demo->pick(self::STREETS).' '.$this->demo->int(1, 120),
                'zip' => '520'.$this->demo->int(62, 80),
                'city' => 'Aachen',
                'council_id' => $this->demo->chance(0.3) ? null : $this->demo->pick($councils)->id,
            ]);
        }
    }

    /**
     * 12 events from two months ago to two months ahead.
     */
    private function createEvents(): void
    {
        foreach (self::EVENTS as $index => $name) {
            $start = $this->demo->today->addDays(-60 + $index * 11)->setTime(16, 0);
            $this->events[] = Event::create([
                'name' => $name,
                'starts_at' => $start,
                'ends_at' => $start->addHours($this->demo->pick([6, 10, 30])),
                'place' => $this->demo->pick(['Foyer', 'Wiese hinter Gebäude W', 'Mensa', 'Hörsaal 1', 'Kellerbar']),
                'responsible_contact_id' => $this->completeContact()->id,
            ]);
        }
    }

    /**
     * Closed, closed with defects, cancelled and unchecked loans in the past, in the order of time.
     */
    private function createPastLoans(): void
    {
        $plan = [];
        foreach (['closed', 'defects', 'cancelled', 'unchecked'] as $status) {
            for ($index = 0; $index < self::COUNTS[$status]; $index++) {
                $plan[] = [$status, $this->demo->int(4, 80)];
            }
        }
        // the oldest first, so an item lent twice comes back in between
        usort($plan, fn (array $a, array $b) => $b[1] <=> $a[1]);

        $recentClosed = 0;
        // the first loan with defects keeps a missing item ("vermisst") for the test sheet
        $missingLoan = null;
        foreach ($plan as [$status, $daysAgo]) {
            $pickup = $this->demo->today->subDays($daysAgo)->setTime($this->demo->pick([10, 14, 16]), 0);
            $return = $pickup->addDays($this->demo->int(1, 3));
            $loan = $this->draft($pickup, $return, $this->pastItems($status === 'unchecked'));

            if ($status === 'cancelled') {
                $this->at($pickup->subDays(5), fn () => $this->confirmLoan->handle($loan));
                $this->at($pickup->subDays(2), fn () => $loan->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_reason' => $this->demo->pick(['Veranstaltung abgesagt', 'Doppelt angelegt', 'Termin verschoben'])])->save());

                continue;
            }

            $this->issue($loan, $pickup);
            if ($status === 'unchecked') {
                $this->at($return, fn () => $this->returnLoan->acceptUnchecked($this->demo->actor, $loan, $this->signatures('returner', 'receiver'), false, $loan->responsibleName(), 'Demo'));

                continue;
            }

            $keepsMissing = $status === 'defects' && $missingLoan === null;
            $missingLoan = $keepsMissing ? $loan : $missingLoan;
            $this->at($return, function () use ($loan, $status, $keepsMissing) {
                foreach ($this->returnLoan->rows($loan, false) as $index => $row) {
                    if ($status === 'defects' && $index === 0) {
                        $state = $this->demo->pick([ReturnState::Missing, ReturnState::Damaged, ReturnState::Dirty]);
                        $state = $keepsMissing ? ReturnState::Missing : $state;
                        $this->returnLoan->defect($this->demo->actor, $loan, false, $row, $state, $this->demo->pick(['Kabel gerissen', 'Gehäuse gebrochen', 'Bierflecken', 'nicht auffindbar']), ItemCondition::where('name', 'defekt')->first(), []);
                    } else {
                        $this->returnLoan->check($this->demo->actor, $loan, false, null, $row->id);
                    }
                }
                $this->returnLoan->complete($this->demo->actor, $loan, $this->signatures('returner', 'receiver'), $this->demo->chance(0.2), $loan->responsibleName(), 'Demo');
            });

            if ($status === 'defects' && ! $keepsMissing && $loan->refresh()->id % 3 === 0) {
                $this->returnLoan->closeDefects($this->demo->actor, $loan, 'Ersatz bezahlt.');
            }

            // put back, except some of the last days ("noch nicht eingeräumt")
            if ($daysAgo > 6 || $recentClosed++ >= 2) {
                $this->at($return->addHours(3), fn () => $this->stow($loan));
            }
        }
    }

    /**
     * Issued, confirmed and draft loans around today, each with items of its own, plus the loans
     * of the test sheet.
     */
    private function createCurrentLoans(): void
    {
        $this->createSheetLoans();

        // one of them is the overdue loan whose rest missed its time, below
        for ($index = 0; $index < self::COUNTS['issued'] - 5; $index++) {
            // every fourth is overdue, every fifth is the organization's own use
            $overdue = $index % 4 === 1;
            $pickup = $this->demo->today->subDays($this->demo->int($overdue ? 6 : 1, $overdue ? 12 : 4))->setTime(14, 0);
            $return = $overdue ? $this->demo->today->subDays($this->demo->int(1, 4))->setTime(12, 0) : $this->demo->today->addDays($this->demo->int(1, 10))->setTime(12, 0);
            $loan = $this->draft($pickup, $return, $this->freeItems(), internal: $index % 5 === 3);
            // the first one is a partial issue: one position stays as rest, one left with a defect
            $this->tryTo(fn () => $this->issue($loan, $pickup, partial: $index === 0));
        }

        // an overdue partial issue: its rest was not picked up in time and holds nothing any more
        $pickup = $this->demo->today->subDays(5)->setTime(14, 0);
        $loan = $this->draft($pickup, $this->demo->today->subDay()->setTime(12, 0), array_filter([$this->takeLendable(), $this->takeLendable()]), purpose: 'Sommerfest (Rest nicht abgeholt)');
        $this->tryTo(fn () => $this->issue($loan, $pickup, partial: true));

        // one of them is the loan that was not picked up, below
        for ($index = 0; $index < self::COUNTS['confirmed'] - 2; $index++) {
            // the first three are picked up today
            $pickup = $index < 3 ? $this->demo->today->setTime(16 + $index, 0) : $this->demo->today->addDays($this->demo->int(1, 50))->setTime(15, 0);
            $loan = $this->draft($pickup, $pickup->addDays($this->demo->int(1, 4)), $this->freeItems());
            $this->tryTo(fn () => $this->confirmLoan->handle($loan));
            // some boxes were checked with the pack pin already
            $box = $loan->positions()->with('item')->get()->first(fn ($position) => $position->item->is_container)?->item;
            if ($box && $loan->refresh()->pack_pin) {
                $this->checkBox->store($box, $loan->pack_pin, 'demo', $this->demo->pick(['Kim Packer', 'Lea', 'Tom']), $box->contents()->pluck('number')->all(), $box->contents()->count());
            }
        }

        // one confirmed loan whose time passed without a pickup ("Nicht abgeholt")
        $pickup = $this->demo->today->subDays(4)->setTime(15, 0);
        $missed = $this->draft($pickup, $pickup->addDays(2), $this->freeItems(), purpose: 'Grillabend (nicht abgeholt)');
        $this->at($pickup->subDay(), fn () => $this->tryTo(fn () => $this->confirmLoan->handle($missed)));

        $confirmed = Loan::where('status', 'confirmed')->where('return_at', '>', $this->demo->today)->with('positions.item')->get();
        for ($index = 0; $index < self::COUNTS['draft'] - 1; $index++) {
            $pickup = $this->demo->today->addDays($this->demo->int(1, 50))->setTime(15, 0);
            // every fourth draft collides with a confirmed loan, the others have items of their own
            $items = $index % 4 === 0 && $confirmed->isNotEmpty() ? [$confirmed[$index % $confirmed->count()]->positions->first()->item] : $this->freeItems();
            $other = $index % 4 === 0 && $confirmed->isNotEmpty() ? $confirmed[$index % $confirmed->count()] : null;
            $this->draft($other ? CarbonImmutable::parse($other->pickup_at) : $pickup, $other ? CarbonImmutable::parse($other->return_at) : $pickup->addDays(2), $items, incomplete: $index % 5 === 1);
        }
    }

    /**
     * The loans whose items are on the test sheet: a confirmed loan picked up today (box with a
     * pack pin check, single piece, bulk item), an issued one, an overdue one, an unchecked box, an
     * internal loan, an item noted by a draft, and a free item for the warning at the issue.
     */
    private function createSheetLoans(): void
    {
        $box = $this->demo->take('box');
        $single = $this->takeLendable();
        $bulk = $this->demo->take('lendable_bulk');
        $pickup = $this->demo->today->setTime(18, 0);
        $today = $this->draft($pickup, $pickup->addDays(2), [$box, $single, [$bulk, max(1, intdiv($bulk->quantity, 2))]], purpose: 'Erstiparty');
        $this->confirmLoan->handle($today);
        // a pin from the seed, so the test sheet names the right one after every run
        $today->forceFill(['pack_pin' => sprintf('%06d', $this->demo->int(100000, 999999))])->save();
        $this->checkBox->store($box, $today->refresh()->pack_pin, 'demo', 'Kim Packer', $box->contents()->pluck('number')->all(), $box->contents()->count());
        $this->demo->label($box->number, $box->name, 'Box, bestätigte Buchung: für die Ausgabe', "In {$today->number} (heute abholen); Pack-PIN {$today->pack_pin}, kontrolliert von Kim Packer.");
        $this->demo->label($single->number, $single->name, 'Position der Buchung zum Abhaken', "Hakt in der Ausgabe von {$today->number} ab.");
        $this->demo->label($bulk->number, $bulk->name, 'Mengenartikel der Buchung', "In {$today->number}: nach dem Scan die Anzahl bestätigen; bei der Ausgabe wird abgeteilt.");

        $issuedBox = $this->demo->take('box');
        $issuedItem = $this->takeLendable();
        $issuedPickup = $this->demo->today->subDays(2)->setTime(14, 0);
        $issued = $this->draft($issuedPickup, $this->demo->today->addDays(3)->setTime(12, 0), [$issuedBox, $issuedItem]);
        $this->issue($issued, $issuedPickup);
        $this->demo->label($issuedBox->number, $issuedBox->name, 'Box, ausgegeben: für die Rückgabe', "In {$issued->number}; im Scan-Tab „Rückgabe dieser Buchung“, gesperrt fürs Umlagern.");
        $this->demo->label($issuedItem->number, $issuedItem->name, 'Ausgegeben, zum Zurücknehmen', "Hakt in der Rückgabe von {$issued->number} ab.");
        $this->pageLoan = $issued;

        $overdueItem = $this->takeLendable();
        $overduePickup = $this->demo->today->subDays(8)->setTime(10, 0);
        $overdue = $this->draft($overduePickup, $this->demo->today->subDays(3)->setTime(12, 0), [$overdueItem]);
        $this->issue($overdue, $overduePickup);
        $this->demo->label($overdueItem->number, $overdueItem->name, 'Überfällig', "In {$overdue->number}, seit drei Tagen überfällig; rot in Kalender und Dashboard.");
        $this->overdueLoan = $overdue;

        $internalItem = $this->takeLendable();
        $internalPickup = $this->demo->today->subDay()->setTime(9, 0);
        $internal = $this->draft($internalPickup, $this->demo->today->addDays(2)->setTime(18, 0), [$internalItem], internal: true);
        $this->issue($internal, $internalPickup);
        // the place of this label on the sheet went to the borrower page (Phase 5)
        $this->demo->label($issued->number, "Verleihseite {$issued->number}", 'Verleihseite ohne Anmeldung', 'Öffnet die Seite des Entleihers: Reiter Chat (eine ungelesene Antwort), Übersicht, Material zum Abhaken; offene Verlängerungsanfrage.', LoanPage::url($issued));

        // one more issued loan with a partial return
        $partialItems = [$this->takeLendable(), $this->takeLendable()];
        $partialPickup = $this->demo->today->subDays(3)->setTime(15, 0);
        $partial = $this->draft($partialPickup, $this->demo->today->addDay()->setTime(12, 0), $partialItems);
        $this->issue($partial, $partialPickup);
        $this->at($this->demo->today->subDay()->setTime(11, 0), function () use ($partial) {
            $this->returnLoan->check($this->demo->actor, $partial, false, null, $partial->positions()->first()->id);
            $this->returnLoan->complete($this->demo->actor, $partial, $this->signatures('returner', 'receiver'), false, $partial->responsibleName(), 'Demo');
        });

        $unchecked = Loan::where('status', 'unchecked')->whereHas('positions.item', fn ($query) => $query->where('is_container', true))->with('positions.item')->first();
        if ($unchecked) {
            $uncheckedBox = $unchecked->positions->first(fn ($position) => $position->item->is_container)->item;
            $this->demo->label($uncheckedBox->number, $uncheckedBox->name, 'Box, ungeprüft zurück', "Aus {$unchecked->number}; Prüfung offen, Inhalt einzeln abhaken.");
        }

        $notStowed = StowItem::pending()->whereNull('issued_item_id')->whereHas('loan', fn ($query) => $query->where('status', 'closed'))->with('item')->first();
        if ($notStowed) {
            $this->demo->label($notStowed->item->number, $notStowed->item->name, 'Zurück, noch nicht eingeräumt', 'Im Scan-Tab „Einräumen“; danach das Regal des Stammplatzes scannen.');
        }

        $missing = LoanItem::stillMissing()->whereNull('parent_id')->with('missingItem', 'loan')->first();
        if ($missing) {
            $this->demo->label($missing->missingItem->number, $missing->missingItem->name, 'Vermisst nach einer Rückgabe', "Seit {$missing->loan->number}; gesperrt für Buchungen, auf der Buchung „Wieder da“.");
        }

        $noted = $this->takeLendable();
        $draft = $this->draft($this->demo->today->addDays(5)->setTime(15, 0), $this->demo->today->addDays(6)->setTime(12, 0), [$noted]);
        // also the foreign item at the issue of today's loan, so the sheet keeps one page
        $this->demo->label($noted->number, $noted->name, 'Vorgemerkt, fremd bei der Ausgabe', "Vormerkung durch {$draft->number} (Seite, Reiter „Verleih“). Bei der Ausgabe von {$today->number}: Warnung und „Zur Buchung hinzufügen“.");

        // kept out of other loans as before, so the generator gives the same data
        $this->takeLendable();
    }

    /**
     * Borrower pages: chats (one unread by the organization), ticks of the checklist, an open and
     * an accepted extension request, a log of messages (mail and push mixed), a blocked link, and
     * members with own notification settings. Nothing is sent.
     */
    private function createPageData(): void
    {
        $member = $this->member;
        $talk = function (Loan $loan, array $messages) {
            foreach ($messages as [$time, $sender, $body]) {
                $message = $loan->messages()->make([
                    'sender' => $sender,
                    'user_id' => $sender === LoanMessage::USER ? $this->member->id : null,
                    'author_name' => match ($sender) {
                        LoanMessage::CONTACT => 'Entleiher ('.$loan->responsible?->name.')',
                        LoanMessage::USER => $this->member->name,
                        default => 'Stocks',
                    },
                    'body' => $body,
                ]);
                $message->forceFill(['created_at' => $time, 'updated_at' => $time, 'read_by_staff_at' => $time, 'read_by_contact_at' => $time])->save();
            }
        };
        $log = function (Loan $loan, string $type, ?int $stage, CarbonImmutable $time, bool $push) {
            $loan->loadMissing('responsible');
            $loan->notificationLog()->create(['type' => $type, 'stage' => $stage, 'channel' => 'trigger', 'recipient' => 'automatisch', 'sent_at' => $time]);
            $loan->notificationLog()->create(['type' => $type, 'stage' => $stage, 'channel' => 'mail', 'recipient' => "{$loan->responsible?->name} <{$loan->responsible?->email}>", 'sent_at' => $time]);
            if ($push) {
                $loan->notificationLog()->create(['type' => $type, 'stage' => $stage, 'channel' => 'push', 'recipient' => 'Verleihseite: iPhone', 'sent_at' => $time->addSecond()]);
            }
        };

        if ($loan = $this->pageLoan?->refresh()) {
            $start = CarbonImmutable::parse($loan->issued_at);
            $talk($loan, [
                [$start->addHours(3), LoanMessage::CONTACT, 'Hallo, das Kabel für die Box fehlt, oder ist es in der Kiste?'],
                [$start->addHours(4), LoanMessage::USER, 'Das liegt im Deckel der Box, in der kleinen Tasche.'],
                [$start->addHours(5), LoanMessage::CONTACT, 'Gefunden, danke!'],
            ]);
            // the last answer of the borrower is still unread in Stocks
            $loan->messages()->latest('id')->first()->forceFill(['read_by_staff_at' => null])->save();
            foreach ($loan->loanItems()->whereNotNull('issued_at')->limit(2)->get() as $row) {
                $row->forceFill(['borrower_checked_at' => $start->addHours(2)])->save();
            }
            $request = $loan->extensionRequests()->make(['requested_return_at' => CarbonImmutable::parse($loan->return_at)->addDays(2), 'note' => 'Unser Fest wurde um zwei Tage verschoben.']);
            $request->forceFill(['created_at' => $start->addDay(), 'updated_at' => $start->addDay()])->save();
            $talk($loan, [[$start->addDay(), LoanMessage::SYSTEM, 'Verlängerung bis '.$request->requested_return_at->format('d.m.Y, H:i').' Uhr angefragt. Notiz: '.$request->note]]);
            $log($loan, 'confirmed', null, CarbonImmutable::parse($loan->confirmed_at), false);
            $log($loan, 'issued', null, $start, true);
            $loan->forceFill(['notification_preferences' => ['loans.return_reminder' => ['mail' => false]]])->save();
        }

        if ($loan = $this->overdueLoan?->refresh()) {
            $return = CarbonImmutable::parse($loan->return_at);
            $log($loan, 'overdue', 1, $return->addDay(), true);
            $log($loan, 'overdue', 2, $return->addDays(3), true);
            $loan->notificationLog()->create(['type' => 'overdue_staff', 'stage' => 2, 'channel' => 'mail', 'recipient' => "{$member->name} <{$member->email}>", 'sent_at' => $return->addDays(3)]);
            $talk($loan, [
                [$return->addDay()->setTime(9, 30), LoanMessage::USER, 'Hallo, die Rückgabe war gestern. Wann könnt ihr vorbeikommen?'],
                [$return->addDays(2)->setTime(18, 5), LoanMessage::CONTACT, 'Sorry, ich bin krank. Mein Mitbewohner bringt alles am Freitag.'],
            ]);
        }

        // a closed loan with a conversation and an accepted extension
        if ($closed = Loan::where('status', 'closed')->where('kind', 'external')->orderBy('id')->first()) {
            $issued = CarbonImmutable::parse($closed->issued_at);
            $request = $closed->extensionRequests()->make(['requested_return_at' => CarbonImmutable::parse($closed->return_at), 'note' => 'Einen Tag länger, bitte.']);
            $request->forceFill(['status' => LoanExtensionRequest::ACCEPTED, 'answer' => 'Geht klar.', 'decided_by' => $member->id, 'decided_at' => $issued->addHours(6), 'created_at' => $issued->addHours(2), 'updated_at' => $issued->addHours(6)])->save();
            $talk($closed, [
                [$issued->addHours(2), LoanMessage::SYSTEM, 'Verlängerung angefragt. Notiz: Einen Tag länger, bitte.'],
                [$issued->addHours(6), LoanMessage::SYSTEM, 'Verlängerung angenommen. Geht klar.'],
                [$issued->addHours(7), LoanMessage::CONTACT, 'Super, danke euch!'],
            ]);
            $log($closed, 'extension_decided', null, $issued->addHours(6), false);
            $log($closed, 'returned', null, CarbonImmutable::parse($closed->returned_at ?? $closed->return_at), false);
        }

        // a blocked link (e.g. the code was photographed by somebody else)
        Loan::where('status', 'confirmed')->where('kind', 'external')->orderByDesc('id')->first()?->forceFill(['access_blocked_at' => $this->demo->today->subDay()])->save();

        // members with own settings: no chat mails, no overdue messages
        User::where('email', 'demo2@example.com')->first()?->forceFill(['notification_preferences' => ['loans.chat_staff' => ['mail' => false]]])->save();
        User::where('email', 'demo1@example.com')->first()?->forceFill(['notification_preferences' => ['loans.overdue_staff' => ['mail' => false, 'push' => false]]])->save();

        // reminders that would be due right now count as triggered, so the scheduler sends nothing
        // right after seeding; later ones go out as usual
        app(Reminders::class)->run(send: false);
    }

    /**
     * A draft with positions, created a few days before its pickup.
     *
     * @param  list<Item|array{0: Item, 1: int}>  $items
     */
    private function draft(CarbonImmutable $pickup, CarbonImmutable $return, array $items, bool $internal = false, bool $incomplete = false, ?string $purpose = null): Loan
    {
        $created = $pickup->subDays($this->demo->int(2, 20))->min($this->demo->today->subHour());
        $responsible = $incomplete ? $this->incompleteContact() : $this->completeContact();
        $borrower = $this->demo->chance(0.2) ? $this->demo->pick($this->contacts) : $responsible;
        $event = $this->demo->chance(0.3) ? $this->demo->pick($this->events) : null;

        return $this->at($created, function () use ($pickup, $return, $items, $internal, $responsible, $borrower, $event, $purpose) {
            $loan = $this->saveLoan->handle($this->demo->actor, null, [
                'kind' => $internal ? 'internal' : 'external',
                'responsible_contact_id' => $responsible->id,
                'borrower_contact_id' => $borrower->id,
                'responsible_user_id' => $internal ? $this->member->id : null,
                'event_id' => $event?->id,
                'pickup_at' => $pickup->toDateTimeString(),
                'return_at' => $return->toDateTimeString(),
                'purpose' => $purpose ?? ($internal ? 'Eigenbedarf: '.$this->demo->pick(['Sitzung', 'Infostand', 'Erstiwoche']) : $this->demo->pick(self::PURPOSES)),
            ]);
            foreach ($items as $entry) {
                [$item, $quantity] = is_array($entry) ? $entry : [$entry, 1];
                $this->positions->add($loan, $item->refresh()->load('condition'), $quantity);
            }

            return $loan;
        });
    }

    /**
     * Confirm and issue a loan at its pickup: every position ticked off by scan, signed on the screen
     * or (sometimes) on paper. A partial issue keeps the last position back as rest ("Nachausgabe")
     * and notes a defect at the first one.
     */
    private function issue(Loan $loan, CarbonImmutable $pickup, bool $partial = false): void
    {
        $this->at($pickup->subDays(1)->min($this->demo->today->subMinutes(30)), fn () => $this->confirmLoan->handle($loan));
        $this->at($pickup, function () use ($loan, $partial) {
            $this->issueLoan->prepare($loan);
            $positions = $loan->positions()->get();
            foreach ($positions as $index => $position) {
                if ($partial && $positions->count() > 1 && $index === $positions->count() - 1) {
                    continue;
                }
                // a box that came back unchecked leaves with its contents ticked one by one
                if ($position->isUncheckedBox() && $position->children()->exists()) {
                    foreach (LoanItem::whereIn('id', $position->descendantIds())->whereDoesntHave('children')->get() as $row) {
                        $this->issueLoan->check($this->demo->actor, $loan, null, $row->id);
                    }
                } else {
                    $this->issueLoan->check($this->demo->actor, $loan, $position->item->number, null);
                }
                if ($partial && $index === 0) {
                    $this->issueLoan->defect($this->demo->actor, $loan, $position, ReturnState::Dirty, 'Aufkleberreste am Gehäuse', null, [], false);
                }
            }
            $this->issueLoan->complete($this->demo->actor, $loan, $this->signatures('responsible', 'issuer'), $this->demo->chance(0.15), null, 'Demo');
        });
    }

    /**
     * Put everything of a loan back at its home (split parts back to their origin).
     */
    private function stow(Loan $loan): void
    {
        foreach (StowItem::pending()->where('loan_id', $loan->id)->with('item')->get() as $row) {
            $home = $row->item->home();
            if ($row->issued_item_id !== null || $home !== null) {
                $this->stowItem->handle($this->demo->actor, $row, $home ? Item::placeSummary($home)['code'] : null);
            }
        }
    }

    /**
     * Items for a past loan: anything lendable (past loans hold nothing any more); the unchecked
     * ones take a box.
     *
     * @return list<Item|array{0: Item, 1: int}>
     */
    private function pastItems(bool $withBox): array
    {
        $items = [];
        if ($withBox && ($box = $this->demo->take('box'))) {
            $items[] = $box;
        }
        $lendable = $this->demo->tagged('lendable');
        foreach (range(1, $this->demo->int(1, 3)) as $ignored) {
            $item = $lendable[$this->demo->int(0, count($lendable) - 1)]->refresh();
            // an item that came back broken or went missing is not lent again
            if ($this->isLendable($item) && ! in_array($item->id, array_map(fn ($entry) => $entry->id, $items), true)) {
                $items[] = $item;
            }
        }

        return $items ?: [$this->takeLendable()];
    }

    /**
     * Items nobody else holds: one to three single pieces, sometimes a box or a bulk item.
     *
     * @return list<Item|array{0: Item, 1: int}>
     */
    private function freeItems(): array
    {
        $items = [];
        foreach (range(1, $this->demo->int(1, 2)) as $ignored) {
            if ($item = $this->takeLendable()) {
                $items[] = $item;
            }
        }
        // bulk items are shared: a few of them leave enough for the next loan
        $bulks = $this->demo->tagged('lendable_bulk');
        if ($bulks && ($items === [] || $this->demo->chance(0.4))) {
            $bulk = $bulks[$this->demo->int(0, count($bulks) - 1)];
            $items[] = [$bulk, max(1, intdiv($bulk->refresh()->quantity, 8))];
        }
        if ($this->demo->chance(0.15) && ($box = $this->demo->take('box'))) {
            $items[] = $box;
        }

        return $items;
    }

    /**
     * The next single piece nobody holds that can still be lent.
     */
    private function takeLendable(): ?Item
    {
        while ($item = $this->demo->take('lendable')) {
            if ($this->isLendable($item->refresh())) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Check if an item is in the inventory, not broken and lies at a place.
     */
    private function isLendable(Item $item): bool
    {
        return ! $item->isRetired() && ! $item->condition->is_defect && $item->place() !== null;
    }

    private function completeContact(): Contact
    {
        do {
            $contact = $this->demo->pick($this->contacts);
        } while (! $contact->isComplete());

        return $contact;
    }

    private function incompleteContact(): Contact
    {
        do {
            $contact = $this->demo->pick($this->contacts);
        } while ($contact->isComplete());

        return $contact;
    }

    /**
     * Try a step; a loan whose shared bulk item is used up by others stays as it is (a draft with
     * a collision is one of the cases anyway).
     */
    private function tryTo(callable $step): void
    {
        try {
            $step();
        } catch (ValidationException) {
            // left as it is
        }
    }

    /**
     * Run something at a given time of the demo.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function at(CarbonImmutable $time, callable $callback): mixed
    {
        Carbon::setTestNow($time);

        try {
            return $callback();
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Signatures of two roles, all with the demo image.
     *
     * @return array<string, string>
     */
    private function signatures(string ...$roles): array
    {
        return array_fill_keys($roles, $this->signature);
    }

    /**
     * A drawn line as png data uri.
     */
    private function signatureImage(): string
    {
        $image = imagecreatetruecolor(300, 90);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 127));
        $ink = imagecolorallocate($image, 20, 20, 60);
        imagesetthickness($image, 3);
        $x = 10;
        $y = 50;
        for ($step = 0; $step < 14; $step++) {
            $nextX = $x + 20;
            $nextY = 50 + (int) (30 * sin($step * 1.3));
            imageline($image, $x, $y, $nextX, $nextY, $ink);
            [$x, $y] = [$nextX, $nextY];
        }
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }
}
