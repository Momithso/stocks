<?php

namespace Tests\Feature;

use App\Support\Settings;
use Tests\TestCase;

/**
 * Manifest and icons of the web app.
 */
class WebAppTest extends TestCase
{
    public function test_the_manifest_names_the_organization_and_runs_in_full_screen(): void
    {
        app(Settings::class)->set(['organization.name' => 'FSR Informatik', 'organization.short_name' => 'FSR']);

        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertJsonPath('name', 'FSR Informatik')
            ->assertJsonPath('short_name', 'FSR')
            ->assertJsonPath('display', 'fullscreen')
            ->assertJsonCount(3, 'icons');
    }

    public function test_icons_are_png_in_the_given_size(): void
    {
        $response = $this->get('/web-app/icon-192.png')->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->assertSame([192, 192], array_slice(getimagesizefromstring($response->getContent()), 0, 2));
        $this->get('/web-app/icon-100.png')->assertNotFound();
    }
}
