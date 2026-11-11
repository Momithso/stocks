<?php

namespace Tests\Feature\Demo;

use App\Enums\ItemKind;
use App\Models\Item;
use App\Models\Location;
use App\Models\ReservedNumber;
use App\Models\User;
use App\Support\Demo\DemoGenerator;
use App\Support\Demo\InventoryDemo;
use App\Support\Demo\TestSheet;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Demo data of the core (documentation/DEMO.md). The parts of modules have their own tests.
 */
class DemoDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    public function test_the_generator_creates_the_inventory_with_every_case(): void
    {
        app(DemoGenerator::class)->run();

        $this->assertGreaterThanOrEqual(InventoryDemo::ITEMS, Item::count());
        $this->assertSame(60, Location::count());
        $this->assertSame(InventoryDemo::BOXES, Item::where('is_container', true)->count());
        $this->assertTrue(Item::whereNotNull('container_id')->where('is_container', true)->exists(), 'box in box');
        $this->assertTrue(Item::where('kind', ItemKind::Bulk)->whereIn('unit', ['Rollen', 'Packungen'])->exists(), 'consumable');
        $this->assertTrue(Item::whereNotNull('split_from_id')->exists(), 'split off part');
        $this->assertTrue(Item::whereNotNull('retired_at')->exists(), 'retired');
        $this->assertTrue(Item::whereHas('condition', fn ($query) => $query->where('is_defect', true))->exists(), 'defective');
        $this->assertTrue(Item::active()->whereNull('location_id')->whereNull('container_id')->exists(), 'without place');
        $this->assertTrue(Item::whereNull('label_printed_at')->exists(), 'without label');
        $this->assertTrue(Item::whereNotNull('label_hint')->exists(), 'with hint');
        $this->assertTrue(Item::has('photos')->exists(), 'with photos');
        $this->assertTrue(ReservedNumber::open()->exists(), 'reserved number');
        // one account per start role besides the admin
        $this->assertSame(count(config('stocks.start_roles')) + 1, User::where('email', 'like', '%@example.com')->count());
        $this->assertNotNull(app(Settings::class)->get('demo.seeded_at'));
    }

    public function test_the_same_seed_gives_the_same_numbers(): void
    {
        $numbers = function (): array {
            DB::beginTransaction();
            app(DemoGenerator::class)->run(7);
            $numbers = Item::orderBy('id')->pluck('number')->all();
            DB::rollBack();
            Cache::flush();

            return $numbers;
        };

        $this->assertSame($numbers(), $numbers());
    }

    public function test_the_test_sheet_has_a_code_for_every_label(): void
    {
        app(DemoGenerator::class)->run();
        $labels = app(Settings::class)->get('demo.labels');

        $this->assertNotEmpty($labels);
        $unknown = collect($labels)->firstWhere('purpose', 'Unbekannte Nummer');
        foreach ($labels as $label) {
            if ($label === $unknown) {
                continue;
            }
            $known = Item::where('number', $label['code'])->exists() || Location::where('full_code', $label['code'])->exists() || ReservedNumber::where('number', $label['code'])->exists();
            $this->assertTrue($known, "{$label['code']} ({$label['purpose']}) gibt es nicht.");
        }
        $this->assertFalse(Item::where('number', $unknown['code'])->exists());

        $this->assertStringStartsWith('%PDF', app(TestSheet::class)->pdf());
    }

    public function test_the_sheet_can_be_downloaded_only_with_demo_data(): void
    {
        $user = $this->userWithPermissions('labels.print');
        $this->actingAs($user)->get('/labels/demo-sheet.pdf')->assertNotFound();

        app(DemoGenerator::class)->run();

        $this->actingAs($user)->get('/labels/demo-sheet.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
