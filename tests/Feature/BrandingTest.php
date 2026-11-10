<?php

namespace Tests\Feature;

use App\Support\Branding;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Logo and favicon of the instance.
 */
class BrandingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Branding::DISK);
    }

    public function test_a_logo_is_uploaded_and_shown_to_everybody(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/settings/branding', ['logo' => UploadedFile::fake()->image('logo.png', 300, 100)])
            ->assertSessionHasNoErrors();

        $path = app(Settings::class)->get('organization.logo_path');
        Storage::disk(Branding::DISK)->assertExists($path);

        $url = app(Branding::class)->url('logo');
        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('organization.logo_url', $url));

        auth()->logout();
        $this->get($url)->assertOk();
        $this->get('/login')->assertInertia(fn ($page) => $page->where('organization.logo_url', $url));
    }

    public function test_a_new_logo_replaces_the_old_file(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/settings/branding', ['logo' => UploadedFile::fake()->image('alt.png')]);
        $old = app(Settings::class)->get('organization.logo_path');

        $this->actingAs($admin)->post('/admin/settings/branding', ['logo' => UploadedFile::fake()->image('neu.png')]);

        Storage::disk(Branding::DISK)->assertMissing($old);
        $this->assertNotSame($old, app(Settings::class)->get('organization.logo_path'));
    }

    public function test_the_favicon_falls_back_to_the_default(): void
    {
        $this->assertSame('/favicon.ico', app(Branding::class)->url('favicon'));
        $this->get('/branding/favicon')->assertOk();
        $this->get('/branding/logo')->assertNotFound();

        $this->actingAs($this->admin())->post('/admin/settings/branding', ['favicon' => UploadedFile::fake()->image('icon.png', 64, 64)]);
        $this->assertStringStartsWith('/branding/favicon?v=', app(Branding::class)->url('favicon'));
        auth()->logout();
        $this->get('/login')->assertSee(app(Branding::class)->url('favicon'), false);
    }

    public function test_images_can_be_removed(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/settings/branding', ['logo' => UploadedFile::fake()->image('logo.png')]);
        $path = app(Settings::class)->get('organization.logo_path');

        $this->actingAs($admin)->post('/admin/settings/branding', ['remove' => ['logo']])->assertSessionHasNoErrors();

        $this->assertNull(app(Settings::class)->get('organization.logo_path'));
        Storage::disk(Branding::DISK)->assertMissing($path);
    }

    public function test_svg_is_not_allowed(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/settings/branding', ['logo' => UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml')])
            ->assertSessionHasErrors('logo');
    }

    public function test_changing_the_images_needs_the_settings_permission(): void
    {
        $this->actingAs($this->userWithRoles('FSR-Mitglied'))
            ->post('/admin/settings/branding', ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertForbidden();
    }
}
