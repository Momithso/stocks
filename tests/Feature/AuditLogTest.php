<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    public function test_the_log_shows_readable_entries(): void
    {
        $this->enableModelAuditing();
        $admin = $this->admin();
        $user = User::factory()->create(['firstname' => 'Max', 'lastname' => 'Muster']);
        $this->actingAs($admin);
        $user->update(['firstname' => 'Moritz']);

        $this->get('/admin/audit-log?subject_type=User&action=updated')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/AuditLog')
                ->where('audits.data.0.subject_type_label', 'Konto')
                ->where('audits.data.0.subject_label', 'Moritz Muster')
                ->where('audits.data.0.actor.name', $admin->name)
                ->where('audits.data.0.changes.0.label', 'Vorname')
                ->where('audits.data.0.changes.0.old', 'Max')
                ->where('audits.data.0.changes.0.new', 'Moritz'));
    }

    public function test_deleted_records_keep_their_name(): void
    {
        $this->enableModelAuditing();
        $user = User::factory()->create(['firstname' => 'Weg', 'lastname' => 'Damit']);
        $user->delete();

        $this->actingAs($this->admin())->get('/admin/audit-log?action=deleted&subject_type=User')
            ->assertInertia(fn ($page) => $page->where('audits.data.0.subject_label', 'Weg Damit (gelöscht)'));
    }

    public function test_the_log_needs_the_permission(): void
    {
        $this->actingAs($this->userWithRoles('FSR-Mitglied'))->get('/admin/audit-log')->assertForbidden();
    }
}
