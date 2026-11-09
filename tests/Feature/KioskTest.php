<?php

namespace Tests\Feature;

use App\Models\KioskDevice;
use App\Models\User;
use Database\Factories\UserFactory;
use Tests\TestCase;

class KioskTest extends TestCase
{
    private KioskDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->device = KioskDevice::factory()->create();
    }

    /**
     * Send the cookie of the paired device with the next request.
     */
    private function asKiosk(): static
    {
        return $this->withCookie(KioskDevice::COOKIE_NAME, $this->device->cookieValue());
    }

    private function kioskUser(): User
    {
        return User::factory()->withPin()->create()->assignRole('FSR-Mitglied');
    }

    public function test_only_paired_devices_open_the_kiosk(): void
    {
        $this->get('/kiosk')->assertUnauthorized();
        $this->withCookie(KioskDevice::COOKIE_NAME, $this->device->id.'|wrong')->get('/kiosk')->assertUnauthorized();

        $this->get('/kiosk/pair/'.$this->device->id.'?token=wrong')->assertNotFound();
        $this->get($this->device->pairingUrl())->assertRedirect('/kiosk')->assertCookie(KioskDevice::COOKIE_NAME, $this->device->cookieValue());
    }

    public function test_a_new_token_locks_the_tablet_out(): void
    {
        $oldCookie = $this->device->cookieValue();
        $this->actingAs($this->admin())->post("/admin/kiosk-devices/{$this->device->id}/regenerate-token");
        auth()->logout();

        $this->withCookie(KioskDevice::COOKIE_NAME, $oldCookie)->get('/kiosk')->assertUnauthorized();
    }

    public function test_the_lock_screen_lists_only_accounts_that_may_use_the_kiosk(): void
    {
        $member = $this->kioskUser();
        $this->userWithRoles('Freies Mitglied');
        User::factory()->disabled()->create()->assignRole('FSR-Mitglied');

        $this->asKiosk()->get('/kiosk')->assertOk()->assertInertia(fn ($page) => $page->component('Kiosk/Lock')
            ->has('people', 1)
            ->where('people.0.id', $member->id)
            ->where('people.0.has_pin', true)
            ->missing('people.0.pin'));
    }

    public function test_the_kiosk_is_unlocked_with_the_pin(): void
    {
        $member = $this->kioskUser();

        $this->asKiosk()->get('/kiosk/home')->assertRedirect('/kiosk');

        $this->asKiosk()->post('/kiosk/unlock', ['user_id' => $member->id, 'pin' => '111111'])->assertSessionHasErrors('pin');
        $this->asKiosk()->post('/kiosk/unlock', ['user_id' => $member->id, 'pin' => UserFactory::PIN])->assertRedirect('/kiosk/home');

        $this->asKiosk()->get('/kiosk/home')->assertOk()->assertInertia(fn ($page) => $page->where('person.id', $member->id));

        // the kiosk session is no normal login
        $this->assertGuest();
    }

    public function test_the_kiosk_locks_itself_after_inactivity(): void
    {
        $member = $this->kioskUser();
        $this->asKiosk()->post('/kiosk/unlock', ['user_id' => $member->id, 'pin' => UserFactory::PIN]);

        $this->travel(config('kiosk.session_timeout') + 1)->seconds();

        $this->asKiosk()->get('/kiosk/home')->assertRedirect('/kiosk');
    }

    public function test_accounts_without_the_permission_can_not_unlock(): void
    {
        $user = User::factory()->withPin()->create()->assignRole('Freies Mitglied');

        $this->asKiosk()->post('/kiosk/unlock', ['user_id' => $user->id, 'pin' => UserFactory::PIN])->assertNotFound();
    }

    public function test_wrong_pins_are_limited_per_person(): void
    {
        $member = $this->kioskUser();
        for ($i = 0; $i < config('kiosk.pin_max_attempts'); $i++) {
            $this->asKiosk()->post('/kiosk/unlock', ['user_id' => $member->id, 'pin' => '111111']);
        }

        $this->asKiosk()->post('/kiosk/unlock', ['user_id' => $member->id, 'pin' => UserFactory::PIN])
            ->assertSessionHasErrors('pin');
        $this->asKiosk()->get('/kiosk/home')->assertRedirect('/kiosk');
    }

    public function test_wrong_pins_of_many_people_lock_the_device(): void
    {
        config(['kiosk.device_max_failed_pins' => 3]);
        $people = User::factory()->count(3)->withPin()->create()->each->assignRole('FSR-Mitglied');
        foreach ($people as $person) {
            $this->asKiosk()->post('/kiosk/unlock', ['user_id' => $person->id, 'pin' => '111111']);
        }

        $member = $this->kioskUser();
        $this->asKiosk()->post('/kiosk/unlock', ['user_id' => $member->id, 'pin' => UserFactory::PIN])
            ->assertSessionHasErrors(['pin' => 'Zu viele falsche PINs an diesem Gerät. Das Gerät ist noch 10 Minuten gesperrt.']);
    }

    public function test_devices_are_managed_with_the_permission(): void
    {
        $this->actingAs($this->userWithRoles('Vorstand'))->get('/admin/kiosk-devices')->assertForbidden();

        $this->actingAs($this->admin())->post('/admin/kiosk-devices', ['name' => 'Tablet Lager'])->assertSessionHas('success');
        $this->assertDatabaseHas('kiosk_devices', ['name' => 'Tablet Lager']);

        $this->actingAs($this->admin())->get('/admin/kiosk-devices')
            ->assertInertia(fn ($page) => $page->has('devices', 2)->missing('devices.0.token'));
    }
}
