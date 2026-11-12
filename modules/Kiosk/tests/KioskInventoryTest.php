<?php

namespace Modules\Kiosk\Tests;

use App\Models\Item;
use App\Models\Location;
use App\Models\User;
use Database\Factories\UserFactory;
use Modules\Kiosk\Models\KioskDevice;
use Tests\TestCase;

/**
 * Scanning and moving on the kiosk: the pages and actions of the core under /kiosk, with the
 * person of the kiosk and the permissions of the pc.
 */
class KioskInventoryTest extends TestCase
{
    private KioskDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activateModule('kiosk');
        $this->device = KioskDevice::factory()->create();
    }

    private function asKiosk(): static
    {
        return $this->withCookie(KioskDevice::COOKIE_NAME, $this->device->cookieValue());
    }

    /**
     * Unlock the kiosk for a person with the given permissions.
     */
    private function unlockWith(string ...$permissions): User
    {
        $user = User::factory()->withPin()->create();
        $user->givePermissionTo(['kiosk.use', ...$permissions]);
        $this->asKiosk()->post('/kiosk/unlock', ['user_id' => $user->id, 'pin' => UserFactory::PIN]);

        return $user;
    }

    public function test_the_scan_page_needs_an_unlocked_kiosk_and_the_permission(): void
    {
        $this->asKiosk()->get('/kiosk/inventory/scan')->assertRedirect('/kiosk');

        $this->unlockWith();
        $this->asKiosk()->get('/kiosk/inventory/scan')->assertForbidden();
    }

    public function test_the_scan_page_runs_on_the_kiosk(): void
    {
        $this->unlockWith('inventory.view');

        $this->asKiosk()->get('/kiosk/inventory/scan')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Inventory/Scan')
            ->where('surface.name', 'kiosk'));
    }

    public function test_the_lookup_only_links_to_pages_of_the_kiosk(): void
    {
        $this->unlockWith('inventory.view');
        $item = Item::factory()->create();
        $location = Location::factory()->create(['code' => 'EU']);

        $this->asKiosk()->withCredentials()->getJson('/kiosk/inventory/lookup?code='.$item->number)
            ->assertOk()->assertJsonPath('id', $item->id)->assertJsonPath('href', null);
        $this->asKiosk()->withCredentials()->getJson('/kiosk/inventory/lookup?code=EU')
            ->assertOk()->assertJsonPath('id', $location->id)->assertJsonPath('href', null);

        // on the pc the same lookup links to the detail page
        $this->actingAs($this->userWithPermissions('inventory.view'))->getJson('/inventory/lookup?code='.$item->number)
            ->assertJsonPath('href', "/inventory/items/{$item->id}");
    }

    public function test_a_locked_kiosk_answers_json_requests_with_401(): void
    {
        $this->asKiosk()->withCredentials()->getJson('/kiosk/inventory/lookup?code=EU')->assertUnauthorized();
    }

    public function test_moving_on_the_kiosk_is_done_by_the_person_of_the_kiosk(): void
    {
        $person = $this->unlockWith('inventory.view', 'inventory.move');
        $from = Location::factory()->create(['code' => 'EU']);
        $to = Location::factory()->create(['code' => 'GO']);
        $item = Item::factory()->at($from)->create();

        $this->asKiosk()->from('/kiosk/inventory/scan')->post('/kiosk/inventory/move', ['item_id' => $item->id, 'target_code' => 'GO'])
            ->assertRedirect('/kiosk/inventory/scan')
            ->assertSessionHasNoErrors();

        $this->assertTrue($item->fresh()->location->is($to));
        $this->assertDatabaseHas('item_movements', ['item_id' => $item->id, 'user_id' => $person->id]);
    }

    public function test_moving_needs_the_permission_also_on_the_kiosk(): void
    {
        $this->unlockWith('inventory.view');
        Location::factory()->create(['code' => 'GO']);
        $item = Item::factory()->create();

        $this->asKiosk()->post('/kiosk/inventory/move', ['item_id' => $item->id, 'target_code' => 'GO'])->assertForbidden();
    }

    public function test_pages_that_are_not_listed_do_not_exist_on_the_kiosk(): void
    {
        $this->unlockWith('inventory.view', 'inventory.edit', 'inventory.move');
        $item = Item::factory()->create();

        $this->asKiosk()->get("/kiosk/inventory/items/{$item->id}")->assertNotFound();
        $this->asKiosk()->get('/kiosk/inventory/items/create')->assertNotFound();
        $this->asKiosk()->delete("/kiosk/inventory/items/{$item->id}")->assertNotFound();
    }

    public function test_the_kiosk_does_not_open_the_pages_of_the_pc(): void
    {
        $this->unlockWith('inventory.view');

        $this->asKiosk()->get('/inventory/scan')->assertRedirect('/login');
    }

    public function test_labels_are_printed_on_the_kiosk_and_back_stays_on_it(): void
    {
        $this->unlockWith('inventory.view', 'labels.print');
        $item = Item::factory()->create();

        $this->asKiosk()->from('/kiosk/inventory/scan')->get("/kiosk/labels/print?items={$item->id}")
            ->assertOk()->assertInertia(fn ($page) => $page->component('Labels/Print')
            ->has('labels', 1)
            ->where('back', fn (string $back) => str_ends_with($back, '/kiosk/inventory/scan')));

        // a page outside the kiosk is no target of "Zurück"
        $this->asKiosk()->from('/inventory/items')->get("/kiosk/labels/print?items={$item->id}")
            ->assertInertia(fn ($page) => $page->where('back', '/kiosk/inventory/scan'));

        $this->asKiosk()->post('/kiosk/labels/printed', ['items' => [$item->id]])->assertRedirect();
        $this->assertNotNull($item->fresh()->label_printed_at);
    }

    public function test_label_templates_are_not_edited_on_the_kiosk(): void
    {
        $this->unlockWith('labels.print', 'labels.manage');

        $this->asKiosk()->get('/kiosk/labels/templates')->assertNotFound();
        $this->asKiosk()->post('/kiosk/labels/images')->assertNotFound();
    }
}
