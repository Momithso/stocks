<?php

namespace Modules\Loans\Tests;

use App\Models\Item;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\Loans\Actions\ChangePositions;
use Modules\Loans\Actions\ConfirmLoan;
use Modules\Loans\Actions\IssueLoan;
use Modules\Loans\Actions\SaveLoan;
use Modules\Loans\Enums\LoanKind;
use Modules\Loans\Models\Contact;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanItem;
use Tests\TestCase;

/**
 * Base of the tests of the loan module: the module is on, S3 is faked, and there are helpers for
 * contacts, loans and the steps of a loan.
 */
abstract class LoansTestCase extends TestCase
{
    /**
     * A tiny png as data uri, a valid signature for the tests.
     */
    protected const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected Location $shelf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activateModule('loans');
        Storage::fake('s3');
        $this->shelf = Location::forceCreate(['type' => 'room', 'code' => 'R1', 'full_code' => 'R1']);
    }

    /**
     * A contact with complete data.
     */
    protected function contact(array $attributes = []): Contact
    {
        return Contact::create([
            'name' => 'Erika Muster',
            'email' => 'erika@example.com',
            'phone' => '0241 123',
            'street' => 'Eupener Str. 70',
            'zip' => '52066',
            'city' => 'Aachen',
            ...$attributes,
        ]);
    }

    /**
     * An item on the shelf (its home).
     */
    protected function item(array $attributes = []): Item
    {
        return Item::factory()->create(['location_id' => $this->shelf->id, 'home_location_id' => $this->shelf->id, ...$attributes]);
    }

    /**
     * A draft loan with the given items (item or [item, quantity]), from tomorrow 10:00 for one day
     * unless times are given.
     *
     * @param  list<Item|array{0: Item, 1: int}>  $items
     */
    protected function draft(array $items = [], ?Carbon $pickup = null, ?Carbon $return = null, ?User $actor = null, array $data = []): Loan
    {
        $pickup ??= now()->addDay()->setTime(10, 0);
        $contact = $this->contact();
        $loan = app(SaveLoan::class)->handle($actor ?? $this->admin(), null, [
            'kind' => LoanKind::External->value,
            'responsible_contact_id' => $contact->id,
            'borrower_contact_id' => $contact->id,
            'pickup_at' => $pickup->toDateTimeString(),
            'return_at' => ($return ?? $pickup->copy()->addDay())->toDateTimeString(),
            'purpose' => 'Testfeier',
            ...$data,
        ]);
        foreach ($items as $entry) {
            [$item, $quantity] = is_array($entry) ? $entry : [$entry, 1];
            app(ChangePositions::class)->add($loan, $item->load('condition'), $quantity);
        }

        return $loan->refresh();
    }

    /**
     * A confirmed loan.
     *
     * @param  list<Item|array{0: Item, 1: int}>  $items
     */
    protected function confirmed(array $items, ?Carbon $pickup = null, ?Carbon $return = null): Loan
    {
        $loan = $this->draft($items, $pickup, $return);
        app(ConfirmLoan::class)->handle($loan);

        return $loan->refresh();
    }

    /**
     * An issued loan: everything ticked off and signed on the screen.
     *
     * @param  list<Item|array{0: Item, 1: int}>  $items
     */
    protected function issued(array $items, ?Carbon $pickup = null, ?Carbon $return = null, ?User $issuer = null): Loan
    {
        $pickup ??= now()->subHour();
        $loan = $this->confirmed($items, $pickup, $return);
        $issuer ??= $this->admin();
        $issue = app(IssueLoan::class);

        // an overdue loan was issued in its time, not after it
        $travel = $loan->return_at->isPast();
        if ($travel) {
            $this->travelTo($pickup);
        }
        $issue->prepare($loan);
        foreach ($loan->positions as $position) {
            // a box that came back unchecked: its contents one by one, the box comes along
            $rows = $position->isUncheckedBox() && $position->children()->exists()
                ? LoanItem::whereIn('id', $position->descendantIds())->whereDoesntHave('children')->get()
                : [$position];
            foreach ($rows as $row) {
                $issue->check($issuer, $loan, null, $row->id);
            }
        }
        $issue->complete($issuer, $loan, ['responsible' => self::SIGNATURE, 'issuer' => self::SIGNATURE], false, null, 'PHPUnit');
        if ($travel) {
            $this->travelBack();
        }

        return $loan->refresh();
    }
}
