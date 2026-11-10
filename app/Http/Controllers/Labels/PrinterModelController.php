<?php

namespace App\Http\Controllers\Labels;

use App\Http\Controllers\Controller;
use App\Models\PrinterModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Niimbot printer models with their label sizes. New models are added here without programming;
 * the print task names the algorithm of NiimBlueLib (B1, D110, …).
 */
class PrinterModelController extends Controller
{
    /**
     * Create a printer model.
     */
    public function store(Request $request): RedirectResponse
    {
        $model = PrinterModel::create($this->validated($request, null));

        return back()->with('success', "Das Modell „{$model->name}“ wurde angelegt.");
    }

    /**
     * Change a printer model, e.g. add a label size.
     */
    public function update(Request $request, PrinterModel $printerModel): RedirectResponse
    {
        $printerModel->update($this->validated($request, $printerModel));

        return back()->with('success', "Das Modell „{$printerModel->name}“ wurde gespeichert.");
    }

    /**
     * Delete a printer model no printer uses.
     */
    public function destroy(PrinterModel $printerModel): RedirectResponse
    {
        if ($printerModel->printers()->exists()) {
            throw ValidationException::withMessages(['model' => "Drucker nutzen noch das Modell „{$printerModel->name}“."]);
        }

        $printerModel->delete();

        return back()->with('success', "Das Modell „{$printerModel->name}“ wurde gelöscht.");
    }

    /**
     * @return array{name: string, dpi: int, max_width_mm: float, print_task: string, label_sizes: list<array{width: float, height: float}>}
     */
    private function validated(Request $request, ?PrinterModel $model): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('printer_models')->ignore($model)],
            'dpi' => ['required', 'integer', 'between:100,600'],
            'max_width_mm' => ['required', 'numeric', 'between:10,120'],
            'print_task' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_]+$/'],
            'label_sizes' => ['required', 'array', 'min:1', 'max:20'],
            'label_sizes.*.width' => ['required', 'numeric', 'between:5,200'],
            'label_sizes.*.height' => ['required', 'numeric', 'between:5,300'],
        ]);

        $validated['label_sizes'] = array_values(array_map(
            fn (array $size) => ['width' => (float) $size['width'], 'height' => (float) $size['height']],
            $validated['label_sizes'],
        ));

        return $validated;
    }
}
