<?php

namespace Tests\Feature\Labels;

use App\Models\Item;
use App\Models\LabelTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Label templates and their editor.
 */
class TemplateTest extends TestCase
{
    public function test_every_target_has_a_default_template_of_50_by_30_mm(): void
    {
        foreach (['item', 'box', 'location', 'reserve'] as $target) {
            $template = LabelTemplate::where('target', $target)->where('is_default', true)->sole();
            $this->assertSame([50.0, 30.0], [$template->width_mm, $template->height_mm]);
            $this->assertContains('code', array_column($template->elements, 'type'));
        }
    }

    public function test_admins_create_a_template_and_land_in_the_editor(): void
    {
        $this->actingAs($this->admin())->post('/labels/templates', ['name' => 'Groß', 'target' => 'item', 'width_mm' => 100, 'height_mm' => 50])
            ->assertRedirect();

        $template = LabelTemplate::firstWhere('name', 'Groß');
        $this->assertFalse($template->is_default);
        $this->assertSame('code', $template->elements[0]['type']);

        $this->actingAs($this->admin())->get("/labels/templates/{$template->id}/edit")
            ->assertInertia(fn ($page) => $page->component('Labels/Editor')
                ->where('template.name', 'Groß')
                ->has('samples.item.values.name')
                ->where('variables.reserve', ['number', 'owner', 'date', 'contact', 'url']));
    }

    public function test_the_editor_samples_come_from_the_inventory(): void
    {
        Item::factory()->create(['name' => 'Kurz']);
        $long = Item::factory()->create(['name' => 'Ein sehr langer Name für einen Gegenstand', 'label_hint' => 'Achtung, zerbrechlich']);

        $this->actingAs($this->admin())->get('/labels/templates/'.LabelTemplate::value('id').'/edit')
            ->assertInertia(fn ($page) => $page
                ->where('samples.item.values.name', $long->name)
                ->where('samples.item.values.number', $long->number)
                ->where('samples.item.values.hint', 'Achtung, zerbrechlich'));
    }

    public function test_saving_checks_the_elements_and_keeps_one_default_per_target(): void
    {
        $old = LabelTemplate::where('target', 'item')->sole();
        $template = LabelTemplate::create(['name' => 'Neu', 'target' => 'item', 'width_mm' => 50, 'height_mm' => 30, 'elements' => []]);
        $data = ['name' => 'Neu', 'target' => 'item', 'width_mm' => 50, 'height_mm' => 30, 'is_default' => true];

        $this->actingAs($this->admin())->put("/labels/templates/{$template->id}", [...$data, 'elements' => [['id' => 'a', 'type' => 'video', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'rotation' => 0]]])
            ->assertSessionHasErrors('elements.0.type');

        $this->actingAs($this->admin())->put("/labels/templates/{$template->id}", [...$data, 'elements' => [
            ['id' => 'name', 'type' => 'text', 'x' => 1, 'y' => 1, 'w' => 30, 'h' => 5, 'rotation' => 90, 'text' => '{name}', 'font_size' => 9, 'bold' => true, 'unknown' => 'weg'],
        ]])->assertSessionHasNoErrors();

        $this->assertTrue($template->fresh()->is_default);
        $this->assertFalse($old->fresh()->is_default);
        $this->assertArrayNotHasKey('unknown', $template->fresh()->elements[0]);
    }

    public function test_templates_can_be_duplicated_and_deleted(): void
    {
        $template = LabelTemplate::where('target', 'location')->sole();

        $this->actingAs($this->admin())->post("/labels/templates/{$template->id}/duplicate")->assertRedirect();
        $copy = LabelTemplate::firstWhere('name', 'Standort (Kopie)');
        $this->assertFalse($copy->is_default);
        $this->assertSame($template->elements, $copy->elements);

        // the copy becomes the default when the default is deleted
        $this->actingAs($this->admin())->delete("/labels/templates/{$template->id}")->assertRedirect('/labels/templates');
        $this->assertTrue($copy->fresh()->is_default);
    }

    public function test_changes_of_templates_are_audited(): void
    {
        $this->enableModelAuditing();
        $template = LabelTemplate::where('target', 'item')->sole();

        $this->actingAs($this->admin())->put("/labels/templates/{$template->id}", [
            'name' => 'Gegenstand groß', 'target' => 'item', 'width_mm' => 50, 'height_mm' => 30, 'elements' => [],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('audits', ['auditable_type' => LabelTemplate::class, 'auditable_id' => $template->id, 'event' => 'updated']);
    }

    public function test_images_are_uploaded_to_s3_and_delivered(): void
    {
        Storage::fake('s3');

        $response = $this->actingAs($this->admin())->post('/labels/images', ['image' => UploadedFile::fake()->image('warnung.png')])
            ->assertOk();
        Storage::disk('s3')->assertExists($response->json('path'));
        $this->assertStringStartsWith('labels/', $response->json('path'));

        $this->actingAs($this->userWithRoles('FSR-Mitglied'))->get($response->json('url'))->assertOk();
        $this->actingAs($this->admin())->post('/labels/images', ['image' => UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml')])
            ->assertSessionHasErrors('image');
    }

    public function test_only_admins_manage_templates(): void
    {
        $this->actingAs($this->userWithRoles('Vorstand'))->get('/labels/templates')->assertForbidden();
        $this->actingAs($this->admin())->get('/labels/templates')->assertOk();
    }
}
