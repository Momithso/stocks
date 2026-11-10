<?php

namespace Tests\Feature;

use Tests\TestCase;

class NavigationTest extends TestCase
{
    public function test_the_menu_only_shows_entries_the_user_may_use(): void
    {
        // free members see the inventory, but not the categories and not the administration
        $this->actingAs($this->userWithRoles('Freies Mitglied'))->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('navigation', 2)
                ->where('navigation.1.key', 'inventory')
                ->where('navigation.1.entries', fn ($entries) => ! collect($entries)->contains('href', '/inventory/categories')));

        $this->actingAs($this->admin())->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('navigation.2.key', 'admin')
                ->where('navigation.2.entries.0.href', '/admin/users'));
    }

    public function test_rarely_used_pages_are_in_the_settings_group(): void
    {
        $this->actingAs($this->admin())->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('navigation.2.entries.3.title', 'Einstellungen')
                ->where('navigation.2.entries.3.href', null)
                ->where('navigation.2.entries.3.children.0.href', '/admin/settings')
                ->has('navigation.2.entries.3.children', 7));

        // a group only shows the entries the user may use
        $this->actingAs($this->userWithRoles('FSR-Mitglied'))->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('navigation.2.entries.1.title', 'Einstellungen')
                ->has('navigation.2.entries.1.children', 1)
                ->where('navigation.2.entries.1.children.0.href', '/inventory/categories'));
    }

    public function test_the_styleguide_needs_the_settings_permission(): void
    {
        $this->actingAs($this->userWithRoles('FSR-Mitglied'))->get('/styleguide')->assertForbidden();
        $this->actingAs($this->admin())->get('/styleguide')->assertOk();
    }
}
