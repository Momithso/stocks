<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SetupTest extends TestCase
{
    /**
     * A fresh instance without setup.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Setting::query()->delete();
        Cache::flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function wizardData(array $overrides = []): array
    {
        return array_replace_recursive([
            'token' => 'setup-secret',
            'organization' => ['name' => 'Fachschaftsrat Elektrotechnik', 'short_name' => 'FSR 5', 'accent_color' => '#123456'],
            'admin' => ['firstname' => 'Ada', 'lastname' => 'Admin', 'email' => 'ada@example.com', 'password' => 'a very long password', 'password_confirmation' => 'a very long password'],
            'legal' => ['imprint_url' => 'https://example.com/impressum', 'privacy_url' => null, 'contact_email' => null],
            'mail' => ['from_name' => null, 'from_address' => null],
            'roles' => ['Vorstand' => 'Vorsitz'],
            'modules' => ['example'],
        ], $overrides);
    }

    public function test_every_page_leads_to_the_setup_until_it_is_done(): void
    {
        $this->get('/login')->assertRedirect('/setup');
        $this->get('/setup')->assertOk()->assertInertia(fn ($page) => $page->component('Setup')->where('hasToken', true));
    }

    public function test_the_setup_needs_the_token(): void
    {
        $this->post('/setup', $this->wizardData(['token' => 'wrong']))->assertSessionHasErrors('token');
        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
    }

    public function test_the_setup_creates_the_admin_and_the_settings(): void
    {
        $this->post('/setup', $this->wizardData())->assertRedirect('/dashboard');

        $admin = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($admin);
        $this->assertTrue($admin->hasRole(Role::admin()));
        $this->assertSame('FSR 5', app(Settings::class)->get('organization.short_name'));
        $this->assertSame('https://example.com/impressum', app(Settings::class)->get('legal.imprint_url'));
        $this->assertTrue(app(Settings::class)->isSetupCompleted());

        // the setup is only possible once
        $this->get('/setup')->assertNotFound();
        $this->post('/setup', $this->wizardData())->assertNotFound();
    }

    public function test_without_token_the_wizard_is_off(): void
    {
        config(['stocks.setup_token' => null]);

        $this->get('/setup')->assertInertia(fn ($page) => $page->where('hasToken', false));
        $this->post('/setup', $this->wizardData())->assertSessionHasErrors('token');
    }

    public function test_the_install_command_sets_up_the_instance_and_can_run_again(): void
    {
        $arguments = ['--name' => 'FSR 9', '--admin-email' => 'cli@example.com', '--admin-password' => 'a very long password', '--module' => ['example']];

        $this->artisan('stocks:install', $arguments)->assertSuccessful();
        $this->artisan('stocks:install', $arguments)->assertSuccessful();

        $this->assertSame(1, User::where('email', 'cli@example.com')->count());
        $this->assertTrue(app(Settings::class)->isSetupCompleted());
        $this->assertDatabaseHas('modules', ['key' => 'example', 'active' => true]);
        $this->get('/login')->assertOk();
    }
}
