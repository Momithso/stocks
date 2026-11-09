<?php

namespace Tests\Feature;

use App\Actions\Modules\ToggleModule;
use App\Models\Module;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ModuleTest extends TestCase
{
    public function test_every_module_folder_gets_a_switch_and_its_permissions(): void
    {
        $this->assertDatabaseHas('modules', ['key' => 'example', 'active' => false]);
        $this->assertDatabaseHas('permissions', ['name' => 'example.view']);
    }

    public function test_the_admin_role_gets_the_permissions_of_modules(): void
    {
        $this->assertTrue($this->admin()->can('example.view'));
    }

    public function test_modules_are_switched_on_and_off_in_the_administration(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/modules')
            ->assertInertia(fn ($page) => $page->where('modules.0.key', 'example')->where('modules.0.active', false));

        $this->actingAs($admin)->put('/admin/modules/example', ['active' => true])->assertSessionHas('success');
        $this->assertTrue(Module::where('key', 'example')->value('active'));

        $this->actingAs($this->userWithRoles('Vorstand'))->put('/admin/modules/example', ['active' => false])->assertForbidden();
        $this->actingAs($admin)->put('/admin/modules/unknown', ['active' => true])->assertNotFound();
    }

    public function test_requirements_are_checked_when_switching(): void
    {
        $path = storage_path('framework/testing/modules');
        File::deleteDirectory($path);
        foreach (['base' => [], 'addon' => ['base']] as $key => $requires) {
            File::ensureDirectoryExists("{$path}/{$key}");
            File::put("{$path}/{$key}/module.json", json_encode(['key' => $key, 'name' => ucfirst($key), 'provider' => 'Unused', 'requires' => $requires]));
        }
        $this->app->instance(ModuleRegistry::class, $registry = new ModuleRegistry($path));
        $registry->sync();
        $toggle = $this->app->make(ToggleModule::class);

        try {
            $toggle->handle('addon', true);
            $this->fail('addon must not start without base');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Base', $exception->getMessage());
        }

        $toggle->handle('base', true);
        $toggle->handle('addon', true);

        $this->expectException(ValidationException::class);
        $toggle->handle('base', false);
    }
}
