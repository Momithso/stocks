<?php

namespace Modules\Loans\Tests;

use App\Enums\MovementType;
use App\Models\Location;
use Illuminate\Http\UploadedFile;
use Modules\Loans\Enums\DocumentType;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\LoanItem;

/**
 * The issue: scan, tick off, foreign and missing items, freezing the boxes, splitting bulk items,
 * locks on issued items, signatures and documents.
 */
class IssueTest extends LoansTestCase
{
    public function test_scanning_ticks_rows_off_and_reports_foreign_items(): void
    {
        $item = $this->item();
        $loan = $this->confirmed([$item, $this->item()]);
        $foreign = $this->item(['name' => 'Fremder Beamer']);
        $admin = $this->admin();

        $this->actingAs($admin)->get("/loans/{$loan->id}/issue")->assertOk()->assertInertia(fn ($page) => $page->component('Loans::Loans/Issue', false)->has('rows', 2));
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/check", ['code' => "http://x/s/{$item->number}"])->assertSessionHasNoErrors();
        $this->assertTrue((bool) $loan->positions()->where('item_id', $item->id)->value('checked_by_scan'));

        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/check", ['code' => $foreign->number])->assertSessionHas('foreign.id', $foreign->id)->assertSessionHas('foreign.free', true);

        // something not ticked off can not leave
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/complete", ['signatures' => ['responsible' => self::SIGNATURE, 'issuer' => self::SIGNATURE]])->assertSessionHasErrors('loan');
        $this->assertSame(LoanStatus::Confirmed, $loan->refresh()->status);
    }

    public function test_a_ticked_box_ticks_its_contents_and_the_last_content_ticks_the_box(): void
    {
        $box = $this->item(['is_container' => true]);
        $first = $this->item(['location_id' => null, 'container_id' => $box->id]);
        $second = $this->item(['location_id' => null, 'container_id' => $box->id]);
        $loan = $this->confirmed([$box]);
        $admin = $this->admin();
        $this->actingAs($admin)->get("/loans/{$loan->id}/issue");

        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/check", ['code' => $box->number]);
        $this->assertSame(3, $loan->loanItems()->whereNotNull('checked_at')->count());

        $row = $loan->loanItems()->where('item_id', $first->id)->first();
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/rows/{$row->id}/uncheck");
        $this->assertNull($loan->positions()->first()->checked_at);

        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/check", ['code' => $first->number]);
        $this->assertNotNull($loan->positions()->first()->checked_at);
        unset($second);
    }

    public function test_the_issue_freezes_boxes_moves_items_out_and_locks_them(): void
    {
        $box = $this->item(['is_container' => true, 'name' => 'Partybox']);
        $inside = $this->item(['location_id' => null, 'container_id' => $box->id, 'name' => 'Lichterkette']);
        $loan = $this->issued([$box]);

        $this->assertSame(LoanStatus::Issued, $loan->status);
        $this->assertNull($box->refresh()->location_id);
        $this->assertSame($this->shelf->id, $box->home_location_id);
        $this->assertSame($box->id, $inside->refresh()->container_id);
        $this->assertSame(MovementType::Issue, $box->movements()->first()->type);
        $this->assertTrue(LoanItem::where('loan_id', $loan->id)->where('item_id', $inside->id)->where('parent_id', '!=', null)->exists());

        // repacking the box later does not change the signed list
        $later = $this->item(['location_id' => null, 'container_id' => $box->id, 'name' => 'Später dazu']);
        $this->assertSame(['Partybox', 'Lichterkette'], array_column($loan->documents()->first()->snapshot['positions'], 'name'));

        // locked while issued, the item page and the scan card say so
        $this->actingAs($this->admin())->post('/inventory/move', ['item_id' => $box->id, 'target_code' => $this->shelf->full_code])->assertSessionHasErrors('item');
        $this->actingAs($this->admin())->post("/inventory/items/{$inside->id}/retire", ['reason' => 'lost'])->assertSessionHasErrors('item');
        $this->actingAs($this->admin())->get("/inventory/items/{$box->id}")->assertInertia(fn ($page) => $page->where('notices.0.lock', true)->where('notices.0.text', fn ($text) => str_starts_with($text, 'Ausgegeben an Erika Muster bis')));
        $this->actingAs($this->admin())->getJson("/inventory/lookup?code={$inside->number}")->assertJsonPath('notices.0.actions.0.title', 'Rückgabe dieser Buchung');
        $this->actingAs($this->admin())->get('/inventory/items')->assertInertia(fn ($page) => $page->where('items.data', fn ($rows) => collect($rows)->firstWhere('id', $box->id)['badges'][0]['text'] !== null));
        unset($later);
    }

    public function test_bulk_items_are_split_at_the_issue(): void
    {
        $benches = $this->item(['kind' => 'bulk', 'quantity' => 40, 'unit' => 'Stück', 'name' => 'Bierbank']);
        $loan = $this->issued([[$benches, 20]]);

        $position = $loan->positions()->first();
        $this->assertNotNull($position->issued_item_id);
        $this->assertSame(20, $benches->refresh()->quantity);
        $this->assertSame(20, $position->issuedItem->quantity);
        $this->assertNull($position->issuedItem->location_id);
        // the rest at home is not locked, the part is
        Location::forceCreate(['type' => 'room', 'code' => 'R2', 'full_code' => 'R2']);
        $this->actingAs($this->admin())->post('/inventory/move', ['item_id' => $benches->id, 'target_code' => 'R2', 'quantity' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->admin())->post('/inventory/move', ['item_id' => $position->issued_item_id, 'target_code' => $this->shelf->full_code])->assertSessionHasErrors('item');
    }

    public function test_signatures_are_required_unless_signed_on_paper(): void
    {
        $item = $this->item();
        $loan = $this->confirmed([$item], now()->subHour());
        $admin = $this->admin();
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/check", ['code' => $item->number]);

        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/complete", ['signatures' => ['responsible' => 'data:image/png;base64,bm90IGEgcG5n']])->assertSessionHasErrors();
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/complete", ['on_paper' => true, 'scan' => UploadedFile::fake()->image('blatt.jpg')])->assertSessionHasNoErrors();

        $loan->refresh();
        $this->assertSame(LoanStatus::Issued, $loan->status);
        $this->assertSame(4, $loan->signatures()->where('on_paper', true)->count());
        $this->assertTrue($loan->documents()->where('type', DocumentType::PaperScan)->exists());
        $this->assertSame($admin->id, $loan->issuer_id);
    }

    public function test_an_early_pickup_starts_the_time_now(): void
    {
        $loan = $this->issued([$this->item()], now()->addDays(2), now()->addDays(3));

        $this->assertTrue($loan->pickup_at->lte(now()));
    }

    public function test_someone_else_picking_up_becomes_the_responsible_person(): void
    {
        $loan = $this->confirmed([$this->item()]);
        $other = $this->contact(['name' => 'Jana Neu', 'phone' => null]);

        $this->actingAs($this->admin())->post("/loans/{$loan->id}/issue/responsible", ['responsible_contact_id' => $other->id])->assertSessionHasErrors('responsible_contact_id');
        $other->update(['phone' => '1']);
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/issue/responsible", ['responsible_contact_id' => $other->id])->assertSessionHasNoErrors();
        $this->assertSame($other->id, $loan->refresh()->responsible_contact_id);
    }
}
