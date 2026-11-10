<?php

namespace Tests\Feature\Labels;

use App\Models\Item;
use App\Models\Location;
use App\Support\Settings;
use Tests\TestCase;

/**
 * Print preview with real data, batches, timestamps of the last print.
 */
class PrintTest extends TestCase
{
    public function test_the_server_delivers_the_values_of_the_variables(): void
    {
        app(Settings::class)->set(['organization.name' => 'FSR Informatik', 'legal.contact_email' => 'fsr@example.org']);
        config(['app.url' => 'https://stocks.example']);
        $shelf = Location::factory()->in(Location::factory()->create(['code' => 'EU', 'full_code' => 'EU']))->create(['code' => 'R03']);
        $item = Item::factory()->bulk(5, 'Rollen')->at($shelf)->create(['name' => 'Gaffa', 'label_hint' => 'Nicht für Teppich']);

        $this->actingAs($this->userWithRoles('FSR-Mitglied'))->get("/labels/print?items={$item->id}&locations={$shelf->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Labels/Print')
                ->has('labels', 2)
                ->where('labels.0.target', 'item')
                ->where('labels.0.url', "https://stocks.example/s/{$item->number}")
                ->where('labels.0.values.name', 'Gaffa')
                ->where('labels.0.values.quantity', '5 Rollen')
                ->where('labels.0.values.location', 'EU-R03')
                ->where('labels.0.values.owner', 'FSR Informatik')
                ->where('labels.0.values.contact', 'fsr@example.org')
                ->where('labels.0.values.hint', 'Nicht für Teppich')
                ->where('labels.1.target', 'location')
                ->where('labels.1.values.code', 'EU-R03')
                ->where('labels.1.values.location', 'EU'));
    }

    public function test_batches_take_the_contents_of_a_box_and_the_locations_below(): void
    {
        $box = Item::factory()->container()->create();
        $inner = Item::factory()->container()->at($box)->create();
        Item::factory()->at($inner)->create();
        Item::factory()->at($box)->create(['retired_at' => now()]);

        $room = Location::factory()->create();
        $shelf = Location::factory()->in($room)->create();
        Location::factory()->in($shelf)->count(2)->create();

        $this->actingAs($this->userWithRoles('FSR-Mitglied'))->get("/labels/print?box={$box->id}&below={$room->id}")
            ->assertInertia(fn ($page) => $page
                ->has('labels', 5)
                ->where('labels.0.target', 'box')
                ->where('labels.1.target', 'item')
                ->where('labels.2.target', 'location'));
    }

    public function test_printing_sets_the_timestamp_used_by_the_filter_without_label(): void
    {
        $member = $this->userWithRoles('FSR-Mitglied');
        $printed = Item::factory()->create();
        $new = Item::factory()->create();
        $location = Location::factory()->create();

        $this->actingAs($member)->post('/labels/printed', ['items' => [$printed->id], 'locations' => [$location->id]])->assertRedirect();

        $this->assertNotNull($printed->fresh()->label_printed_at);
        $this->assertNotNull($location->fresh()->label_printed_at);
        $this->actingAs($member)->get('/inventory/items?unlabeled=1')
            ->assertInertia(fn ($page) => $page->where('items.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$new->id]));
    }

    public function test_printing_needs_the_permission(): void
    {
        $this->actingAs($this->userWithRoles('Freies Mitglied'))->get('/labels/print')->assertForbidden();
        $this->actingAs($this->userWithRoles('Vorstand'))->get('/labels/print')->assertOk();
    }

    public function test_the_label_hint_is_a_field_of_the_item(): void
    {
        $item = Item::factory()->create();

        $this->actingAs($this->admin())->put("/inventory/items/{$item->id}", [
            'name' => $item->name, 'kind' => 'single', 'label_hint' => 'Achtung, zerbrechlich',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Achtung, zerbrechlich', $item->fresh()->label_hint);
    }

    public function test_a_split_off_part_offers_to_print_its_label(): void
    {
        $from = Location::factory()->create();
        $to = Location::factory()->create();
        $item = Item::factory()->bulk(10, 'Rollen')->at($from)->create();

        $this->actingAs($this->userWithRoles('FSR-Mitglied'))
            ->post('/inventory/move', ['item_id' => $item->id, 'target_code' => $to->full_code, 'quantity' => 3])
            ->assertSessionHas('label', fn (array $label) => $label['number'] !== $item->number);
    }
}
