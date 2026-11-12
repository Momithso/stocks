<?php

namespace App\Http\Controllers\Labels;

use App\Enums\LabelTarget;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\LabelTemplate;
use App\Models\Location;
use App\Models\Printer;
use App\Models\PrinterModel;
use App\Models\ReservedNumber;
use App\Support\Branding;
use App\Support\Demo\DemoGenerator;
use App\Support\Labels\LabelData;
use App\Support\Labels\LabelElements;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Label templates and their editor (Verwaltung › Einstellungen › Etiketten). Changes are audited.
 */
class TemplateController extends Controller
{
    /**
     * Disk of uploaded images, the same as for photos of items.
     */
    public const IMAGE_DISK = 's3';

    /**
     * Display the templates.
     */
    public function index(): Response
    {
        return Inertia::render('Labels/Templates', [
            'templates' => LabelTemplate::orderBy('target')->orderBy('name')->get()->map(fn (LabelTemplate $template) => $template->summary()),
            'targets' => LabelTarget::options(),
            'sizes' => self::sizes(),
            // the test label sheet can be downloaded in an instance with demo data
            'demoSheet' => app(DemoGenerator::class)->hasDemoData(),
        ]);
    }

    /**
     * Create an empty template with a code element and open the editor.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'target' => ['required', Rule::enum(LabelTarget::class)],
            'width_mm' => ['required', 'numeric', 'between:5,'.LabelElements::MAX_SIZE_MM],
            'height_mm' => ['required', 'numeric', 'between:5,'.LabelElements::MAX_SIZE_MM],
        ]);
        $side = max(5, min($validated['width_mm'], $validated['height_mm']) - 4);

        $template = LabelTemplate::create([
            ...$validated,
            'elements' => [['id' => 'code', 'type' => 'code', 'x' => 2, 'y' => 2, 'w' => $side, 'h' => $side, 'rotation' => 0, 'symbology' => 'qrcode']],
            // the first template of a target is its default
            'is_default' => ! LabelTemplate::where('target', $validated['target'])->exists(),
        ]);

        return to_route('labels.templates.edit', $template);
    }

    /**
     * Display the editor of a template with a sample of real data.
     */
    public function edit(LabelTemplate $template, LabelData $labels, Settings $settings, Branding $branding): Response
    {
        return Inertia::render('Labels/Editor', [
            'template' => $template->summary(),
            'printers' => Printer::with('printerModel')->orderBy('name')->get()->map(fn (Printer $printer) => $printer->summary()),
            // a separate logo for print can replace the screen logo (e.g. a dark one instead of a white one)
            'logoUrl' => $branding->url($branding->printKind()),
            'targets' => LabelTarget::options(),
            'variables' => collect(LabelTarget::cases())->mapWithKeys(fn (LabelTarget $target) => [$target->value => $target->variables()]),
            'sizes' => self::sizes(),
            'samples' => self::samples($labels),
        ]);
    }

    /**
     * Save a template, also from the print preview ("Als Vorlage speichern").
     */
    public function update(Request $request, LabelTemplate $template): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'target' => ['required', Rule::enum(LabelTarget::class)],
            'width_mm' => ['required', 'numeric', 'between:5,'.LabelElements::MAX_SIZE_MM],
            'height_mm' => ['required', 'numeric', 'between:5,'.LabelElements::MAX_SIZE_MM],
            'is_default' => ['boolean'],
            ...LabelElements::rules(),
        ]);

        DB::transaction(function () use ($template, $validated) {
            $template->update([
                ...array_intersect_key($validated, array_flip(['name', 'target', 'width_mm', 'height_mm'])),
                'elements' => LabelElements::clean($validated['elements']),
            ]);
            if ($validated['is_default'] ?? false) {
                $template->makeDefault();
            }
        });

        return back()->with('success', "Die Vorlage „{$template->name}“ wurde gespeichert.");
    }

    /**
     * Copy a template.
     */
    public function duplicate(LabelTemplate $template): RedirectResponse
    {
        $copy = $template->replicate();
        $copy->name = Str::limit("{$template->name} (Kopie)", 100, '');
        $copy->is_default = false;
        $copy->save();

        return to_route('labels.templates.edit', $copy)->with('success', "„{$copy->name}“ wurde angelegt.");
    }

    /**
     * Delete a template. Another template of the target becomes the default.
     */
    public function destroy(LabelTemplate $template): RedirectResponse
    {
        DB::transaction(function () use ($template) {
            $template->delete();
            if ($template->is_default) {
                LabelTemplate::where('target', $template->target)->orderBy('id')->first()?->makeDefault();
            }
        });

        return to_route('labels.templates.index')->with('success', "Die Vorlage „{$template->name}“ wurde gelöscht.");
    }

    /**
     * Upload a free image for a template (json with path and url). SVG is not allowed, because it
     * can contain scripts.
     */
    public function storeImage(Request $request): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048']]);

        $file = $request->file('image');
        $path = 'labels/'.Str::random(40).'.'.$file->extension();
        if (! Storage::disk(self::IMAGE_DISK)->put($path, $file->getContent())) {
            throw ValidationException::withMessages(['image' => 'Das Bild konnte nicht gespeichert werden. Ist der Speicher (S3) erreichbar?']);
        }

        return response()->json(['path' => $path, 'url' => route('labels.images.show', basename($path), absolute: false)]);
    }

    /**
     * Deliver an image of a template. Delivered by Stocks itself, so the browser can draw it into
     * the print image (same origin).
     */
    public function showImage(string $name): StreamedResponse
    {
        $path = "labels/{$name}";
        abort_unless(Storage::disk(self::IMAGE_DISK)->exists($path), 404);

        return Storage::disk(self::IMAGE_DISK)->response($path, headers: ['Cache-Control' => 'private, max-age=86400']);
    }

    /**
     * Label sizes of all printer models, for choosing the size of a template.
     *
     * @return list<array{label: string, width: float, height: float}>
     */
    public static function sizes(): array
    {
        return PrinterModel::orderBy('name')->get()
            ->flatMap(fn (PrinterModel $model) => array_map(fn (array $size) => [
                'label' => "{$size['width']} × {$size['height']} mm ({$model->name})",
                'width' => (float) $size['width'],
                'height' => (float) $size['height'],
            ], $model->label_sizes))
            ->values()->all();
    }

    /**
     * One example per target from the inventory, so the editor shows if long names fit. Falls back
     * to invented data in an empty inventory.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function samples(LabelData $labels): array
    {
        $item = Item::active()->where('is_container', false)->orderByRaw('LENGTH(name) DESC')->first();
        $box = Item::active()->where('is_container', true)->orderByRaw('LENGTH(name) DESC')->first();
        $location = Location::orderByRaw('LENGTH(full_code) DESC')->first();
        $reserved = new ReservedNumber(['number' => $item?->number ?? 'INV-000016']);
        $reserved->id = 0;

        return [
            'item' => $item ? $labels->forItem($item) : self::invented($labels, 'item', 'Verlängerungskabel Schuko 25 m', 'INV-000016'),
            'box' => $box ? $labels->forItem($box) : self::invented($labels, 'box', 'Box Bar Kabel und Werkzeug', 'INV-000024'),
            'location' => $location ? $labels->forLocation($location) : self::invented($labels, 'location', 'EU-014-R03.4', 'EU-014-R03.4'),
            'reserve' => $labels->forReserved($reserved),
        ];
    }

    /**
     * Invented sample of a target for an empty inventory.
     *
     * @return array<string, mixed>
     */
    private static function invented(LabelData $labels, string $target, string $name, string $code): array
    {
        $location = new Location(['full_code' => $code, 'name' => $target === 'location' ? 'Regal 3, Fach 4' : null]);
        $location->id = 0;
        $data = $labels->forLocation($location);

        return [
            ...$data,
            'target' => $target,
            'title' => $name,
            'values' => [
                ...$data['values'],
                'name' => $target === 'location' ? 'Regal 3, Fach 4' : $name,
                'category' => 'Technik › Strom',
                'quantity' => '1',
                'home' => 'EU-014-R03.4',
                'location' => 'EU-014-R03',
                'description' => '',
                'condition' => 'gut',
                'hint' => $target === 'item' ? 'Achtung, zerbrechlich' : '',
                'count' => $target === 'box' ? '12' : '',
            ],
        ];
    }
}
