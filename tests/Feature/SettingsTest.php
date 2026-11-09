<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    public function test_defaults_work_without_stored_values(): void
    {
        $this->assertSame('#22948c', app(Settings::class)->get('organization.accent_color'));
    }

    public function test_settings_are_changed_with_the_permission_and_audited(): void
    {
        $this->enableModelAuditing();
        $data = [
            'organization' => ['name' => 'Neuer Name', 'short_name' => 'Neu', 'accent_color' => '#ff0000'],
            'legal' => ['imprint_url' => null, 'privacy_url' => null, 'contact_email' => null],
            'mail' => ['from_name' => null, 'from_address' => null],
        ];

        $this->actingAs($this->userWithRoles('FSR-Mitglied'))->put('/admin/settings', $data)->assertForbidden();
        $this->actingAs($this->admin())->put('/admin/settings', $data)->assertSessionHas('success');

        $this->assertSame('Neuer Name', app(Settings::class)->get('organization.name'));
        $this->assertDatabaseHas('audits', ['auditable_type' => Setting::class]);
    }

    public function test_the_accent_colour_and_the_name_are_in_every_page(): void
    {
        app(Settings::class)->set(['organization.accent_color' => '#ff0000', 'organization.short_name' => 'FSR X']);

        $this->actingAs($this->admin())->get('/dashboard')
            ->assertSee('--color-accent: 255 0 0', false)
            ->assertInertia(fn ($page) => $page->where('organization.short_name', 'FSR X'));
    }

    public function test_mails_use_the_sender_of_the_settings(): void
    {
        app(Settings::class)->set(['mail.from_address' => 'fsr@example.com', 'mail.from_name' => 'FSR']);

        Mail::raw('Test', fn ($message) => $message->to('someone@example.com'));

        $sent = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('fsr@example.com', $sent->getFrom()[0]->getAddress());
        $this->assertSame('FSR', $sent->getFrom()[0]->getName());
    }

    public function test_no_font_or_script_is_loaded_from_other_servers(): void
    {
        $content = $this->get('/login')->getContent();

        $this->assertDoesNotMatchRegularExpression('/<(link|script)[^>]+(href|src)="https?:\/\/(?!localhost)/', $content);
    }
}
