<?php

namespace Modules\Kiosk\Tests;

use App\Models\User;
use App\Support\Settings;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Modules\Kiosk\Enums\KioskPlatform;
use Modules\Kiosk\Models\KioskDevice;
use Tests\TestCase;

/**
 * The kiosk as surface: the person, the remaining time, the end of a task, the platform and the
 * settings of the session.
 */
class KioskSessionTest extends TestCase
{
    private KioskDevice $device;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activateModule('kiosk');
        $this->device = KioskDevice::factory()->create(['platform' => KioskPlatform::Android]);
        $this->member = User::factory()->withPin()->create()->assignRole('FSR-Mitglied');
    }

    private function asKiosk(): static
    {
        return $this->withCookie(KioskDevice::COOKIE_NAME, $this->device->cookieValue());
    }

    private function unlock(): void
    {
        $this->asKiosk()->post('/kiosk/unlock', ['user_id' => $this->member->id, 'pin' => UserFactory::PIN]);
    }

    public function test_the_routes_answer_404_while_the_module_is_off(): void
    {
        $this->deactivateModule('kiosk');

        $this->asKiosk()->get('/kiosk')->assertNotFound();
        $this->get($this->device->pairingUrl())->assertNotFound();
        $this->asKiosk()->get('/kiosk/inventory/scan')->assertNotFound();
        $this->actingAs($this->admin())->get('/admin/kiosk-devices')->assertNotFound();
    }

    public function test_pages_get_the_surface_with_person_time_and_platform(): void
    {
        $this->unlock();

        $this->asKiosk()->get('/kiosk/home')->assertInertia(fn ($page) => $page
            ->where('surface.name', 'kiosk')
            ->where('surface.base', '/kiosk')
            ->where('surface.layout', 'Kiosk::KioskLayout')
            ->where('surface.platform', 'android')
            ->where('surface.direct_print', true)
            ->where('surface.person.id', $this->member->id)
            ->where('surface.expires_in', 120)
            ->where('surface.lock_after_task', false)
            ->where('navigation', []));

        // the pc knows no surface
        $this->actingAs($this->member)->get('/dashboard')->assertInertia(fn ($page) => $page->where('surface', null));
    }

    public function test_the_person_is_logged_in_only_for_the_kiosk_request(): void
    {
        $this->unlock();

        $this->asKiosk()->get('/kiosk/home')->assertOk()->assertInertia(fn ($page) => $page->where('auth.user.id', $this->member->id));
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_extend_answers_with_the_remaining_time(): void
    {
        $this->asKiosk()->withCredentials()->postJson('/kiosk/extend')->assertUnauthorized();

        $this->unlock();
        $this->travel(100)->seconds();
        $this->asKiosk()->withCredentials()->postJson('/kiosk/extend')->assertOk()->assertJson(['expires_in' => 120]);

        $this->travel(100)->seconds();
        $this->asKiosk()->get('/kiosk/home')->assertOk();
    }

    public function test_the_timeout_comes_from_the_settings(): void
    {
        app(Settings::class)->set(['kiosk.session_timeout' => 300]);
        $this->unlock();

        $this->travel(200)->seconds();
        $this->asKiosk()->get('/kiosk/home')->assertOk()->assertInertia(fn ($page) => $page->where('surface.expires_in', 300));

        $this->travel(301)->seconds();
        $this->asKiosk()->get('/kiosk/home')->assertRedirect('/kiosk');
    }

    public function test_after_a_task_the_kiosk_goes_home_and_keeps_the_message(): void
    {
        $this->unlock();

        $this->asKiosk()->withSession(['success' => 'Ausgegeben.'])->get('/kiosk/done')
            ->assertRedirect('/kiosk/home')->assertSessionHas('success', 'Ausgegeben.');
        $this->asKiosk()->get('/kiosk/home')->assertOk();
    }

    public function test_after_a_task_the_kiosk_locks_when_set(): void
    {
        app(Settings::class)->set(['kiosk.lock_after_task' => true]);
        $this->unlock();

        $this->asKiosk()->withSession(['success' => 'Ausgegeben.'])->get('/kiosk/done')
            ->assertRedirect('/kiosk')->assertSessionHas('success', 'Ausgegeben.');
        $this->asKiosk()->get('/kiosk/home')->assertRedirect('/kiosk');
    }

    public function test_the_lock_screen_shows_the_hint_of_the_platform(): void
    {
        $this->asKiosk()->get('/kiosk')->assertInertia(fn ($page) => $page
            ->where('installHint', KioskPlatform::Android->installHint()));
    }

    public function test_the_kiosk_has_its_own_web_app(): void
    {
        $this->asKiosk()->get('/kiosk')->assertSee('href="/kiosk/manifest.webmanifest"', false);
        $this->get('/login')->assertSee('href="/manifest.webmanifest"', false);

        $this->asKiosk()->get('/kiosk/manifest.webmanifest')->assertOk()
            ->assertJsonPath('start_url', '/kiosk')
            ->assertJsonPath('scope', '/kiosk');
    }

    public function test_devices_get_a_platform(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/kiosk-devices', ['name' => 'iPad', 'platform' => 'ipados'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('kiosk_devices', ['name' => 'iPad', 'platform' => 'ipados']);

        $this->actingAs($admin)->put("/admin/kiosk-devices/{$this->device->id}", ['name' => 'Lager', 'platform' => 'ipados'])->assertSessionHasNoErrors();
        $this->assertSame(KioskPlatform::Ipados, $this->device->fresh()->platform);
        $this->assertFalse($this->device->fresh()->platform->directPrint());

        $this->actingAs($admin)->put("/admin/kiosk-devices/{$this->device->id}", ['name' => 'Lager', 'platform' => 'windows'])->assertSessionHasErrors('platform');
    }

    public function test_the_settings_of_the_session_are_validated(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/admin/kiosk-settings', ['session_timeout' => 10, 'lock_after_task' => true])->assertSessionHasErrors('session_timeout');
        $this->actingAs($admin)->put('/admin/kiosk-settings', ['session_timeout' => 600, 'lock_after_task' => false])->assertSessionHasNoErrors();

        $this->assertSame(600, (int) app(Settings::class)->get('kiosk.session_timeout'));
        $this->assertFalse((bool) app(Settings::class)->get('kiosk.lock_after_task'));
        $this->actingAs($this->userWithRoles('Vorstand'))->put('/admin/kiosk-settings', ['session_timeout' => 600, 'lock_after_task' => false])->assertForbidden();
    }

    public function test_old_audit_entries_of_devices_move_into_the_module(): void
    {
        $id = DB::table('audits')->insertGetId(['event' => 'created', 'auditable_type' => 'App\\Models\\KioskDevice', 'auditable_id' => $this->device->id, 'created_at' => now(), 'updated_at' => now()]);
        $migration = require base_path('modules/Kiosk/database/migrations/2026_11_14_000001_move_kiosk_device_audits.php');

        $migration->up();
        $this->assertSame(KioskDevice::class, DB::table('audits')->where('id', $id)->value('auditable_type'));

        $migration->down();
        $this->assertSame('App\\Models\\KioskDevice', DB::table('audits')->where('id', $id)->value('auditable_type'));
    }
}
