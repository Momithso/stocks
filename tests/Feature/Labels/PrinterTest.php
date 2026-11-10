<?php

namespace Tests\Feature\Labels;

use App\Models\Printer;
use App\Models\PrinterModel;
use Tests\TestCase;

/**
 * Printers and niimbot printer models.
 */
class PrinterTest extends TestCase
{
    public function test_the_b1_and_two_printers_come_with_the_installation(): void
    {
        $b1 = PrinterModel::firstWhere('name', 'B1');
        $this->assertSame([203, 48.0, 'B1'], [$b1->dpi, $b1->max_width_mm, $b1->print_task]);
        $this->assertSame([['width' => 50, 'height' => 30]], $b1->label_sizes);

        $this->assertSame('niimbot', Printer::firstWhere('name', 'Niimbot B1')->driver->value);
        $this->assertSame('paper', Printer::firstWhere('name', 'Normaler Drucker')->driver->value);
    }

    public function test_printers_keep_only_the_settings_of_their_driver(): void
    {
        $b1 = PrinterModel::firstWhere('name', 'B1');

        $this->actingAs($this->admin())->post('/labels/printers', [
            'name' => 'B1 Büro', 'driver' => 'niimbot', 'printer_model_id' => $b1->id,
            'settings' => ['density' => 4, 'offset_x' => 0.5, 'offset_y' => -1, 'rotation' => 90, 'page' => 'sheet'],
        ])->assertSessionHasNoErrors();

        $printer = Printer::firstWhere('name', 'B1 Büro');
        $this->assertSame(['density' => 4, 'offset_x' => 0.5, 'offset_y' => -1, 'rotation' => 90], $printer->settings);

        $this->actingAs($this->admin())->post('/labels/printers', ['name' => 'Ohne Modell', 'driver' => 'niimbot', 'settings' => ['density' => 3, 'offset_x' => 0, 'offset_y' => 0, 'rotation' => 0]])
            ->assertSessionHasErrors('printer_model_id');
        $this->actingAs($this->admin())->post('/labels/printers', ['name' => 'Bogen', 'driver' => 'paper', 'settings' => ['page' => 'sheet', 'columns' => 3, 'rows' => 8, 'margin_top' => 10, 'margin_left' => 5, 'gap_x' => 2, 'gap_y' => 0]])
            ->assertSessionHasNoErrors();
    }

    public function test_new_models_and_label_sizes_can_be_added(): void
    {
        $this->actingAs($this->admin())->post('/labels/printer-models', [
            'name' => 'D110', 'dpi' => 203, 'max_width_mm' => 15, 'print_task' => 'D110',
            'label_sizes' => [['width' => 30, 'height' => 15]],
        ])->assertSessionHasNoErrors();

        $b1 = PrinterModel::firstWhere('name', 'B1');
        $this->actingAs($this->admin())->put("/labels/printer-models/{$b1->id}", [
            'name' => 'B1', 'dpi' => 203, 'max_width_mm' => 48, 'print_task' => 'B1',
            'label_sizes' => [['width' => 50, 'height' => 30], ['width' => 40, 'height' => 60]],
        ])->assertSessionHasNoErrors();

        $this->assertCount(2, $b1->fresh()->label_sizes);
        $this->assertDatabaseHas('printer_models', ['name' => 'D110', 'print_task' => 'D110']);
    }

    public function test_a_model_in_use_can_not_be_deleted(): void
    {
        $b1 = PrinterModel::firstWhere('name', 'B1');

        $this->actingAs($this->admin())->delete("/labels/printer-models/{$b1->id}")->assertSessionHasErrors('model');
        $this->assertModelExists($b1);
    }

    public function test_only_admins_manage_printers(): void
    {
        $this->actingAs($this->userWithRoles('Vorstand'))->get('/labels/printers')->assertForbidden();
        $this->actingAs($this->admin())->get('/labels/printers')->assertOk();
    }
}
