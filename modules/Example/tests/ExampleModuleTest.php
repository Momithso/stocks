<?php

namespace Modules\Example\Tests;

use App\Models\KioskDevice;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves that every extension point of the core works for a module.
 */
class ExampleModuleTest extends TestCase
{
    public function test_migrations_of_modules_run_also_while_the_module_is_off(): void
    {
        $this->assertTrue(Schema::hasTable('example_notes'));
    }

    public function test_routes_answer_404_while_the_module_is_off(): void
    {
        $this->actingAs($this->admin())->get('/example')->assertNotFound();

        $this->activateModule('example');
        $this->actingAs($this->admin())->get('/example')->assertOk()->assertInertia(fn ($page) => $page->component('Example::Index', false));
        // inertia only checks pages of the core, module pages are named "<Module>::<Page>"
        $this->assertFileExists(base_path('modules/Example/resources/js/pages/Index.vue'));
    }

    public function test_menu_entry_and_dashboard_tile_only_appear_while_the_module_is_on(): void
    {
        $admin = $this->admin();
        $hasEntry = fn ($navigation) => collect($navigation)->flatMap(fn ($section) => $section['entries'])->contains('href', '/example');

        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('tiles', fn ($tiles) => ! collect($tiles)->contains('Example::DashboardTile'))
            ->where('navigation', fn ($navigation) => ! $hasEntry($navigation)));

        $this->activateModule('example');

        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('tiles', fn ($tiles) => collect($tiles)->contains('Example::DashboardTile'))
            ->where('navigation', fn ($navigation) => $hasEntry($navigation)));
    }

    public function test_the_permission_of_the_module_is_needed(): void
    {
        $this->activateModule('example');

        $this->actingAs($this->userWithRoles('FSR-Mitglied'))->get('/example')->assertForbidden();
        $this->actingAs($this->userWithPermissions('example.view'))->get('/example')->assertOk();
    }

    public function test_the_kiosk_tile_leads_to_the_kiosk_page_of_the_module(): void
    {
        $this->activateModule('example');
        $device = KioskDevice::factory()->create();
        $user = User::factory()->withPin()->create();
        $user->givePermissionTo(['kiosk.use', 'example.view']);

        $this->withCookie(KioskDevice::COOKIE_NAME, $device->cookieValue())
            ->post('/kiosk/unlock', ['user_id' => $user->id, 'pin' => UserFactory::PIN]);

        $this->withCookie(KioskDevice::COOKIE_NAME, $device->cookieValue())->get('/kiosk/home')
            ->assertInertia(fn ($page) => $page->where('tiles.0.href', '/kiosk/example'));
        $this->withCookie(KioskDevice::COOKIE_NAME, $device->cookieValue())->get('/kiosk/example')
            ->assertOk()->assertInertia(fn ($page) => $page->component('Example::Kiosk', false));
    }
}
