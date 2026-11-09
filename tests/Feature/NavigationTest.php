<?php

namespace Tests\Feature;

use Tests\TestCase;

class NavigationTest extends TestCase
{
    public function test_the_menu_only_shows_entries_the_user_may_use(): void
    {
        $this->actingAs($this->userWithRoles('Freies Mitglied'))->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('navigation', 1)->where('navigation.0.key', 'overview'));

        $this->actingAs($this->admin())->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('navigation.1.key', 'admin')
                ->where('navigation.1.entries.0.href', '/admin/users'));
    }

    public function test_the_styleguide_needs_the_settings_permission(): void
    {
        $this->actingAs($this->userWithRoles('FSR-Mitglied'))->get('/styleguide')->assertForbidden();
        $this->actingAs($this->admin())->get('/styleguide')->assertOk();
    }
}
