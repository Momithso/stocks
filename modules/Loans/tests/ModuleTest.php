<?php

namespace Modules\Loans\Tests;

use App\Models\Role;
use App\Support\Extensions\ItemExtensions;
use App\Support\Modules\ModuleRegistry;
use Modules\Loans\Models\Council;
use Tests\TestCase;

/**
 * The module as a whole: switched off it shows nothing, the start roles get its permissions.
 */
class ModuleTest extends TestCase
{
    public function test_switched_off_all_routes_answer_404_and_the_core_runs_unchanged(): void
    {
        $admin = $this->admin();
        foreach (['/loans', '/loans/calendar', '/loans/contacts', '/loans/returns', '/loans/settings', '/loans/stats'] as $url) {
            $this->actingAs($admin)->get($url)->assertNotFound();
        }

        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->where('tiles', fn ($tiles) => ! collect($tiles)->contains('Loans::DashboardTile')));
        $this->assertSame([], app(ItemExtensions::class)->tabsFor($admin));
        $this->actingAs($admin)->get('/inventory/items')->assertOk();
    }

    public function test_switched_on_the_menu_has_the_section_loans(): void
    {
        $this->activateModule('loans');

        $this->actingAs($this->admin())->get('/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->where('navigation', fn ($sections) => collect($sections)->contains('key', 'loans')));
    }

    public function test_the_start_roles_get_the_permissions_of_the_module(): void
    {
        $this->assertTrue(Role::findByName('Vorstand')->hasPermissionTo('loans.settings'));
        $this->assertTrue(Role::findByName('FSR-Mitglied')->hasPermissionTo('loans.issue'));
        $this->assertFalse(Role::findByName('Freies Mitglied')->hasPermissionTo('loans.issue'));
        $this->assertTrue(Role::findByName('Freies Mitglied')->hasPermissionTo('loans.view'));
        $this->assertContains('loans', array_keys(app(ModuleRegistry::class)->all()));
    }

    public function test_the_migration_creates_ten_councils(): void
    {
        $this->assertSame(10, Council::count());
        $this->assertSame('FSR 1', Council::orderBy('number')->value('name'));
    }
}
