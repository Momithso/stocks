<?php

namespace Tests\Feature\Labels;

use App\Models\Item;
use App\Models\ReservedNumber;
use App\Support\Inventory\ItemNumbers;
use Tests\TestCase;

/**
 * Stock labels with reserved numbers.
 */
class ReservedNumberTest extends TestCase
{
    public function test_numbers_are_reserved_from_the_counter_and_printed(): void
    {
        $member = $this->userWithRoles('FSR-Mitglied');

        $response = $this->actingAs($member)->post('/labels/reserved', ['count' => 3]);

        $reserved = ReservedNumber::orderBy('id')->get();
        $this->assertCount(3, $reserved);
        $response->assertRedirect('/labels/print?reserved='.urlencode($reserved->pluck('id')->implode(',')));

        // the next item gets the number after the reserved ones
        $item = $this->createItem($member);
        $this->assertSame(app(ItemNumbers::class)->prefix().'-00004'.ItemNumbers::checkDigit('00004'), $item->number);

        $this->actingAs($member)->get($response->headers->get('Location'))
            ->assertInertia(fn ($page) => $page->has('labels', 3)->where('labels.0.target', 'reserve')->where('labels.0.code', $reserved[0]->number));
    }

    public function test_an_item_takes_a_reserved_number_only_once(): void
    {
        $member = $this->userWithRoles('FSR-Mitglied');
        $this->actingAs($member)->post('/labels/reserved', ['count' => 1]);
        $reserved = ReservedNumber::sole();

        $this->actingAs($member)->get("/inventory/items/create?number={$reserved->number}")
            ->assertInertia(fn ($page) => $page->where('reservedNumber', $reserved->number));

        $item = $this->createItem($member, $reserved->number);
        $this->assertSame($reserved->number, $item->number);
        $this->assertNotNull($reserved->fresh()->used_at);

        $this->actingAs($member)->post('/inventory/items', $this->itemData($reserved->number))->assertSessionHasErrors('reserved_number');
    }

    public function test_released_numbers_are_never_given_out_again(): void
    {
        $member = $this->userWithRoles('FSR-Mitglied');
        $this->actingAs($member)->post('/labels/reserved', ['count' => 2]);
        $reserved = ReservedNumber::orderBy('id')->get();

        $this->actingAs($member)->post('/labels/reserved/release', ['ids' => [$reserved[0]->id]])->assertSessionHas('success');

        $this->assertNotNull($reserved[0]->fresh()->released_at);
        $this->actingAs($member)->post('/inventory/items', $this->itemData($reserved[0]->number))->assertSessionHasErrors('reserved_number');
        $this->assertNotContains($reserved[0]->number, [$this->createItem($member)->number]);
        $this->actingAs($member)->get('/labels/reserved')->assertInertia(fn ($page) => $page->has('reserved', 1));
    }

    public function test_the_scan_of_a_reserved_number_offers_to_create_the_item(): void
    {
        $this->actingAs($this->admin())->post('/labels/reserved', ['count' => 1]);
        $reserved = ReservedNumber::sole();

        $this->actingAs($this->userWithRoles('FSR-Mitglied'))->getJson("/inventory/lookup?code={$reserved->number}")
            ->assertOk()
            ->assertJsonPath('type', 'reserved')
            ->assertJsonPath('href', '/inventory/items/create?number='.urlencode($reserved->number));
    }

    /**
     * @return array<string, mixed>
     */
    private function itemData(?string $number = null): array
    {
        return ['name' => 'Beamer', 'kind' => 'single', 'condition_id' => $this->conditionId('neu'), 'reserved_number' => $number];
    }

    private function createItem($actor, ?string $number = null): Item
    {
        $this->actingAs($actor)->post('/inventory/items', $this->itemData($number))->assertSessionHasNoErrors();

        return Item::latest('id')->first();
    }
}
