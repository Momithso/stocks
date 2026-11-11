<?php

namespace Modules\Loans\Http;

use App\Http\Controllers\Controller;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Loans\Models\Council;
use Modules\Loans\Support\ContractTemplate;
use Modules\Loans\Support\LoanDocuments;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Settings of the loan module (Verwaltung › Einstellungen › Verleih): contract text and lender,
 * buffer between loans, number prefix, councils.
 */
class SettingsController extends Controller
{
    /**
     * Display the settings.
     */
    public function edit(Settings $settings, ContractTemplate $template, LoanDocuments $documents): Response
    {
        return Inertia::render('Loans::Settings', [
            'settings' => [...$settings->module('loans'), 'loans.contract_template' => $template->text()],
            'isDefaultText' => $settings->get('loans.contract_template') === null,
            'variables' => collect(ContractTemplate::VARIABLES)->map(fn (string $label, string $name) => ['name' => $name, 'label' => $label])->values(),
            'example' => $documents->exampleContract($template->text()),
            'councils' => Council::withCount('contacts')->orderBy('number')->get(),
        ]);
    }

    /**
     * Save the settings. "reset_text" goes back to the text that comes with the module.
     */
    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'loans.lender' => ['required', 'string', 'max:300'],
            'loans.place' => ['nullable', 'string', 'max:100'],
            'loans.buffer_hours' => ['required', 'integer', 'min:0', 'max:168'],
            'loans.number_prefix' => ['required', 'regex:/^[A-Z0-9]{1,6}$/'],
            'loans.contract_template' => ['required', 'string', 'max:50000'],
            'reset_text' => ['boolean'],
        ], ['loans.number_prefix.regex' => 'Das Präfix hat 1 bis 6 Großbuchstaben oder Ziffern.'], [
            'loans.lender' => 'Verleiher',
            'loans.place' => 'Ort der Unterschrift',
            'loans.buffer_hours' => 'Puffer',
            'loans.number_prefix' => 'Präfix',
            'loans.contract_template' => 'Vertragstext',
        ]);

        $values = collect($validated)->except('reset_text')->dot()->all();
        // the text that comes with the module is not stored, so updates of it arrive
        if ($request->boolean('reset_text') || trim($values['loans.contract_template']) === trim(ContractTemplate::default())) {
            $values['loans.contract_template'] = null;
        }
        $values['loans.buffer_hours'] = (int) $values['loans.buffer_hours'];
        $settings->set($values);

        return back()->with('success', 'Die Einstellungen des Verleihs wurden gespeichert.');
    }

    /**
     * The saved contract text as pdf with an example loan.
     */
    public function preview(ContractTemplate $template, LoanDocuments $documents): HttpResponse
    {
        $pdf = Pdf::setPaper('a4')->loadView('loans::pdf.contract', [
            'doc' => ['number' => 'Beispiel', 'organization' => app(Settings::class)->get('organization.name'), 'address' => [], 'contact' => null, 'contract' => $documents->exampleContract($template->text()), 'pickup' => '', 'return' => '', 'purpose' => null, 'event' => null, 'date' => now()->format('d.m.Y H:i')],
            'signatures' => [],
            'hash' => null,
            'logo' => null,
        ])->output();

        return response($pdf, headers: ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="Leihvertrag Vorschau.pdf"']);
    }

    /**
     * Add a council.
     */
    public function storeCouncil(Request $request): RedirectResponse
    {
        Council::create($this->validatedCouncil($request, null));

        return back()->with('success', 'Die Fachschaft wurde angelegt.');
    }

    /**
     * Change a council, e.g. switch it off.
     */
    public function updateCouncil(Request $request, Council $council): RedirectResponse
    {
        $council->update($this->validatedCouncil($request, $council));

        return back()->with('success', "{$council->name} wurde gespeichert.");
    }

    /**
     * Delete a council without contacts; with contacts it can only be switched off.
     */
    public function destroyCouncil(Council $council): RedirectResponse
    {
        if ($council->contacts()->exists()) {
            throw ValidationException::withMessages(['council' => "{$council->name} hat Kontakte. Schalte sie stattdessen aus."]);
        }
        $council->delete();

        return back()->with('success', "{$council->name} wurde gelöscht.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedCouncil(Request $request, ?Council $council): array
    {
        return $request->validate([
            'number' => ['required', 'integer', 'min:1', 'max:999', Rule::unique('councils', 'number')->ignore($council?->id)],
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ]);
    }
}
