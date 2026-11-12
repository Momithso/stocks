<?php

namespace Tests\Feature;

use App\Support\MailSettings;
use App\Support\Settings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Mail server in the settings instead of the environment, connection status and test mail.
 */
class MailSettingsTest extends TestCase
{
    public function test_the_server_is_saved_with_an_encrypted_password_that_never_reaches_the_browser(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/admin/mail', ['host' => 'smtp.example.org', 'port' => 587, 'username' => 'fsr', 'password' => 'geheim', 'encryption' => 'tls', 'from_address' => 'fsr@example.org'])
            ->assertSessionHasNoErrors();

        $settings = app(Settings::class);
        $this->assertSame('smtp.example.org', $settings->get('mail.host'));
        $this->assertSame('geheim', Crypt::decryptString($settings->get('mail.password')));

        $this->actingAs($admin)->get('/admin/mail')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Mail')->where('mail.has_password', true)->missing('mail.password'));
        $this->actingAs($admin)->get('/admin/settings')->assertInertia(fn ($page) => $page->missing('settings.mail\.password'));

        // an empty password keeps the stored one
        $this->actingAs($admin)->put('/admin/mail', ['host' => 'smtp.example.org', 'password' => '']);
        $this->assertSame('geheim', Crypt::decryptString(app(Settings::class)->get('mail.password')));

        app(MailSettings::class)->apply();
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('geheim', config('mail.mailers.smtp.password'));
    }

    public function test_without_a_server_the_environment_applies(): void
    {
        $status = app(MailSettings::class)->status(fresh: true);

        $this->assertNull($status['ok']);
        $this->assertSame('array', config('mail.default'));
    }

    public function test_an_unreachable_server_is_reported_and_shown_in_the_menu(): void
    {
        app(Settings::class)->set(['mail.host' => '127.0.0.1', 'mail.port' => 1]);

        $status = app(MailSettings::class)->status(fresh: true);
        $this->assertFalse($status['ok']);
        // the error of the server itself, not one of our own code
        $this->assertStringContainsString('127.0.0.1', $status['message']);
        $this->actingAs($this->admin())->get('/dashboard')->assertInertia(fn ($page) => $page->where('navigation', function ($sections) {
            $entries = collect($sections)->flatMap(fn ($section) => $section['entries'])->flatMap(fn ($entry) => $entry['children'] ?? [$entry]);

            return $entries->firstWhere('title', 'Benachrichtigungen')['badge']['text'] === 'getrennt';
        }));
    }

    public function test_a_test_mail_is_sent(): void
    {
        Mail::fake();

        $this->actingAs($this->admin())->post('/admin/mail/test', ['to' => 'test@example.org'])->assertSessionHas('success');
        $this->actingAs($this->userWithPermissions('users.view'))->post('/admin/mail/test', ['to' => 'test@example.org'])->assertForbidden();
    }
}
