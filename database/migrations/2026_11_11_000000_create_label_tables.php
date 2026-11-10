<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. See documentation/LABELS.md for templates, printers and drivers.
     */
    public function up(): void
    {
        // label printer models of the niimbot driver; new models are added without programming
        Schema::create('printer_models', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->unsignedSmallInteger('dpi');
            // width of the print head, wider labels are printed in the middle
            $table->decimal('max_width_mm', 5, 1);
            // name of the print task in NiimBlueLib, e.g. "B1"
            $table->string('print_task', 30);
            // list of {width, height} in mm
            $table->json('label_sizes');
            $table->timestamps();
        });

        // printers of the instance: driver "paper" (browser print dialog) or "niimbot"
        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('driver', 20);
            $table->foreignId('printer_model_id')->nullable()->constrained()->restrictOnDelete();
            // paper: page format; niimbot: density, offset and rotation (see App\Models\Printer)
            $table->json('settings');
            $table->timestamps();
        });

        // label templates: size in mm and freely placed elements (see App\Support\Labels\LabelElements)
        Schema::create('label_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('target', 20);
            $table->decimal('width_mm', 5, 1);
            $table->decimal('height_mm', 5, 1);
            $table->json('elements');
            // preselected when printing for its target
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // numbers printed on stock labels before an item exists; taken from the same counter as
        // all item numbers and never given out again, also not when released
        Schema::create('reserved_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        Schema::table('items', function (Blueprint $table) {
            // short text for the label, e.g. "Achtung, zerbrechlich" (variable {hint})
            $table->string('label_hint', 100)->nullable()->after('notes');
            // only the time of the last print is kept, no audit entry
            $table->timestamp('label_printed_at')->nullable()->after('retired_note');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->timestamp('label_printed_at')->nullable()->after('full_code');
        });

        $this->seed();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('locations', fn (Blueprint $table) => $table->dropColumn('label_printed_at'));
        Schema::table('items', fn (Blueprint $table) => $table->dropColumn(['label_hint', 'label_printed_at']));
        Schema::dropIfExists('reserved_numbers');
        Schema::dropIfExists('label_templates');
        Schema::dropIfExists('printers');
        Schema::dropIfExists('printer_models');
    }

    /**
     * The model B1, a niimbot and a paper printer, and one template per target in 50 × 30 mm.
     */
    private function seed(): void
    {
        $now = now();

        $modelId = DB::table('printer_models')->insertGetId([
            'name' => 'B1', 'dpi' => 203, 'max_width_mm' => 48, 'print_task' => 'B1',
            'label_sizes' => json_encode([['width' => 50, 'height' => 30]]),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('printers')->insert([
            [
                'name' => 'Niimbot B1', 'driver' => 'niimbot', 'printer_model_id' => $modelId,
                'settings' => json_encode(['density' => 3, 'offset_x' => 0, 'offset_y' => 0, 'rotation' => 0]),
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'Normaler Drucker', 'driver' => 'paper', 'printer_model_id' => null,
                'settings' => json_encode(['page' => 'single', 'columns' => 2, 'rows' => 8, 'margin_top' => 10, 'margin_left' => 10, 'gap_x' => 5, 'gap_y' => 5]),
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        $code = fn (string $id) => ['id' => $id, 'type' => 'code', 'x' => 1.5, 'y' => 1.5, 'w' => 20, 'h' => 20, 'rotation' => 0, 'symbology' => 'qrcode'];
        $text = fn (string $id, string $text, float $x, float $y, float $w, float $h, float $size, bool $bold = false, string $align = 'left') => [
            'id' => $id, 'type' => 'text', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'rotation' => 0,
            'text' => $text, 'font_size' => $size, 'bold' => $bold, 'align' => $align, 'valign' => 'top', 'overflow' => 'shrink',
        ];
        $hint = $text('hint', '{hint}', 1.5, 23, 47, 5.5, 9, true, 'center');

        $templates = [
            'item' => ['Gegenstand', [
                $code('code'),
                $text('name', '{name}', 23.5, 1.5, 25, 11, 10, true),
                $text('number', '{number}', 23.5, 13, 25, 4, 8, true),
                $text('owner', 'Eigentum von {owner}', 23.5, 17.5, 25, 4, 6),
                $hint,
            ]],
            'box' => ['Box', [
                $code('code'),
                $text('name', '{name}', 23.5, 1.5, 25, 9, 10, true),
                $text('number', '{number}', 23.5, 11, 25, 4, 8, true),
                $text('count', 'Inhalt: {count} Gegenstände', 23.5, 15, 25, 3.5, 6),
                $text('owner', 'Eigentum von {owner}', 23.5, 18.5, 25, 3.5, 6),
                $hint,
            ]],
            'location' => ['Standort', [
                $code('code'),
                $text('code-text', '{code}', 23.5, 1.5, 25, 9, 14, true),
                $text('name', '{name}', 23.5, 11, 25, 7, 8),
                $text('owner', 'Lagerplatz von {owner}', 1.5, 24, 47, 4, 7, false, 'center'),
            ]],
            'reserve' => ['Vorrat', [
                $code('code'),
                $text('number', '{number}', 23.5, 3, 25, 5, 9, true),
                $text('owner', 'Eigentum von {owner}', 23.5, 9, 25, 7, 7),
                $text('contact', '{contact}', 23.5, 17, 25, 4, 6),
            ]],
        ];

        foreach ($templates as $target => [$name, $elements]) {
            DB::table('label_templates')->insert([
                'name' => $name, 'target' => $target, 'width_mm' => 50, 'height_mm' => 30,
                'elements' => json_encode($elements), 'is_default' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
};
