<?php

namespace App\Http\Controllers\Labels;

use App\Enums\PrinterDriver;
use App\Http\Controllers\Controller;
use App\Models\Printer;
use App\Models\PrinterModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Printers and niimbot printer models (Verwaltung › Einstellungen › Drucker). Plain create, change
 * and delete, so no actions.
 */
class PrinterController extends Controller
{
    /**
     * Display the printers and printer models.
     */
    public function index(): Response
    {
        return Inertia::render('Labels/Printers', [
            'printers' => Printer::with('printerModel')->orderBy('name')->get()->map(fn (Printer $printer) => $printer->summary()),
            'models' => PrinterModel::withCount('printers')->orderBy('name')->get()
                ->map(fn (PrinterModel $model) => [...$model->summary(), 'printers_count' => $model->printers_count]),
            'drivers' => PrinterDriver::options(),
            'defaults' => Printer::DEFAULT_SETTINGS,
        ]);
    }

    /**
     * Create a printer.
     */
    public function store(Request $request): RedirectResponse
    {
        $printer = Printer::create($this->validated($request));

        return back()->with('success', "Der Drucker „{$printer->name}“ wurde angelegt.");
    }

    /**
     * Change a printer.
     */
    public function update(Request $request, Printer $printer): RedirectResponse
    {
        $printer->update($this->validated($request));

        return back()->with('success', "Der Drucker „{$printer->name}“ wurde gespeichert.");
    }

    /**
     * Delete a printer.
     */
    public function destroy(Printer $printer): RedirectResponse
    {
        $printer->delete();

        return back()->with('success', "Der Drucker „{$printer->name}“ wurde gelöscht.");
    }

    /**
     * Validated fields; the settings keep only the keys of the driver.
     *
     * @return array{name: string, driver: string, printer_model_id: int|null, settings: array<string, mixed>}
     */
    private function validated(Request $request): array
    {
        $driver = $request->input('driver');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'driver' => ['required', Rule::enum(PrinterDriver::class)],
            'printer_model_id' => [Rule::requiredIf($driver === PrinterDriver::Niimbot->value), 'nullable', 'integer', 'exists:printer_models,id'],
            'settings' => ['array'],
            ...match ($driver) {
                PrinterDriver::Paper->value => [
                    'settings.page' => ['required', Rule::in(['single', 'sheet'])],
                    'settings.columns' => ['required', 'integer', 'between:1,10'],
                    'settings.rows' => ['required', 'integer', 'between:1,40'],
                    'settings.margin_top' => ['required', 'numeric', 'between:0,100'],
                    'settings.margin_left' => ['required', 'numeric', 'between:0,100'],
                    'settings.gap_x' => ['required', 'numeric', 'between:0,50'],
                    'settings.gap_y' => ['required', 'numeric', 'between:0,50'],
                ],
                PrinterDriver::Niimbot->value => [
                    'settings.density' => ['required', 'integer', 'between:1,5'],
                    'settings.offset_x' => ['required', 'numeric', 'between:-10,10'],
                    'settings.offset_y' => ['required', 'numeric', 'between:-10,10'],
                    'settings.rotation' => ['required', Rule::in([0, 90, 180, 270])],
                ],
                default => [],
            },
        ], [], ['printer_model_id' => 'Modell']);

        return [
            'name' => $validated['name'],
            'driver' => $validated['driver'],
            'printer_model_id' => $validated['driver'] === PrinterDriver::Niimbot->value ? $validated['printer_model_id'] : null,
            'settings' => array_intersect_key($validated['settings'] ?? [], Printer::DEFAULT_SETTINGS[$validated['driver']]),
        ];
    }
}
