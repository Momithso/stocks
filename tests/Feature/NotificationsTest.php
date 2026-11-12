<?php

namespace Tests\Feature;

use App\Notifications\TestNotification;
use App\Support\Notifications\NotificationEvents;
use App\Support\Notifications\WebPush;
use App\Support\Settings;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

/**
 * Notifications of the core: settings in the profile, push devices, test notification.
 */
class NotificationsTest extends TestCase
{
    private const KEYS = ['keys' => ['p256dh' => 'key', 'auth' => 'token']];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->activateModule('loans');
    }

    private function enablePush(): void
    {
        config(['webpush.vapid.public_key' => 'public', 'webpush.vapid.private_key' => 'private']);
    }

    public function test_the_profile_shows_only_events_the_account_may_get(): void
    {
        $this->actingAs($this->userWithRoles('FSR-Mitglied'))->get('/profile')
            ->assertInertia(fn ($page) => $page->has('notifications.events', 3)->where('notifications.events.0.key', 'loans.chat_staff')->where('notifications.push_key', null));

        $this->actingAs($this->userWithRoles('Freies Mitglied'))->get('/profile')
            ->assertInertia(fn ($page) => $page->has('notifications.events', 0));
    }

    public function test_the_settings_store_only_deviations_and_count_for_the_channels(): void
    {
        $user = $this->userWithRoles('FSR-Mitglied');
        $this->actingAs($user)->put('/profile/notifications', ['events' => [
            'loans.chat_staff' => ['mail' => false, 'push' => true],
            'loans.overdue_staff' => ['mail' => true],
            'unknown.event' => ['mail' => false],
        ]])->assertSessionHasNoErrors();

        // mails of members are off until switched on: only that one is stored
        $this->assertSame(['loans.overdue_staff' => ['mail' => true]], $user->refresh()->notification_preferences);
        $events = app(NotificationEvents::class);
        $this->assertFalse($events->wants($user->notification_preferences, 'loans.chat_staff', 'mail'));
        $this->assertTrue($events->wants($user->notification_preferences, 'loans.overdue_staff', 'mail'));
    }

    public function test_push_devices_need_the_keys_and_a_known_push_service(): void
    {
        $user = $this->admin();
        $this->actingAs($user)->postJson('/profile/push', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/a', ...self::KEYS])->assertUnprocessable();

        $this->enablePush();
        $this->postJson('/profile/push', ['endpoint' => 'http://fcm.googleapis.com/fcm/send/a', ...self::KEYS])->assertUnprocessable();
        $this->postJson('/profile/push', ['endpoint' => 'https://169.254.169.254/latest', ...self::KEYS])->assertUnprocessable();
        $this->postJson('/profile/push', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/a', ...self::KEYS], ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0'])
            ->assertOk()->assertJsonPath('device', 'Chrome auf Windows');
        $this->assertSame(1, $user->pushSubscriptions()->count());

        $this->get('/profile')->assertInertia(fn ($page) => $page->has('notifications.devices', 1)->where('notifications.push_key', 'public'));
        $this->deleteJson('/profile/push', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/a'])->assertOk();
        $this->assertSame(0, $user->pushSubscriptions()->count());
    }

    public function test_an_account_has_at_most_ten_devices(): void
    {
        $this->enablePush();
        $user = $this->admin();
        for ($index = 0; $index < WebPush::MAX_PER_OWNER; $index++) {
            $user->pushSubscriptions()->create(['endpoint' => "https://fcm.googleapis.com/fcm/send/{$index}"]);
        }

        $this->actingAs($user)->postJson('/profile/push', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/new', ...self::KEYS])->assertUnprocessable();
        // a known device may renew its keys
        $this->postJson('/profile/push', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/3', ...self::KEYS])->assertOk();
    }

    public function test_the_test_notification_goes_by_mail_or_to_the_devices(): void
    {
        $user = $this->admin();
        $this->actingAs($user)->post('/profile/notify-test', ['channel' => 'mail'])->assertSessionHasNoErrors();
        Notification::assertSentTo($user, TestNotification::class, fn ($notification, array $channels) => $channels === ['mail']);

        $this->post('/profile/notify-test', ['channel' => 'push'])->assertSessionHasErrors('push');

        $this->enablePush();
        $user->pushSubscriptions()->create(['endpoint' => 'https://fcm.googleapis.com/fcm/send/a']);
        $this->post('/profile/notify-test', ['channel' => 'push'])->assertSessionHasNoErrors();
        Notification::assertSentTo($user, TestNotification::class, fn ($notification, array $channels) => $channels === [WebPushChannel::class]);
    }

    public function test_the_command_sends_a_test_notification(): void
    {
        $user = $this->admin();

        $this->artisan('stocks:notify-test', ['email' => strtoupper($user->email)])->assertSuccessful();
        Notification::assertSentTo($user, TestNotification::class);
        $this->artisan('stocks:notify-test', ['email' => $user->email, '--channel' => 'push'])->assertFailed();
        $this->artisan('stocks:notify-test', ['email' => 'nobody@example.com'])->assertFailed();
    }

    public function test_the_push_message_is_short_and_opens_its_address(): void
    {
        $push = (new TestNotification('push'))->toWebPush($this->admin())->toArray();

        $this->assertSame(route('profile.edit'), $push['data']['url']);
        $this->assertLessThanOrEqual(120, mb_strlen($push['body']));
    }

    public function test_the_organization_has_a_central_phone_number(): void
    {
        $this->actingAs($this->admin())->put('/admin/settings', ['organization' => ['name' => 'FSR', 'short_name' => 'FSR', 'accent_color' => '#22948c', 'phone' => 'abc']])
            ->assertSessionHasErrors('organization.phone');
        $this->put('/admin/settings', ['organization' => ['name' => 'FSR', 'short_name' => 'FSR', 'accent_color' => '#22948c', 'phone' => '+49 241 6009-0']])
            ->assertSessionHasNoErrors();
        $this->assertSame('+49 241 6009-0', app(Settings::class)->get('organization.phone'));
    }
}
