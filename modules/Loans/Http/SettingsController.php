<?php

namespace Modules\Loans\Http;

use App\Http\Controllers\Controller;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
 * buffer between loans, number prefix, councils, borrower page and messages.
 */
class SettingsController extends Controller
{
    /**
     * Messages of the borrower side with an extra text in the settings.
     *
     * @var array<string, string>
     */
    public const MAIL_TEXTS = [
        'confirmed' => 'Buchung bestätigt', 'pickup_reminder' => 'Erinnerung an die Abholung', 'issued' => 'Ausgabe erfolgt',
        'return_reminder' => 'Erinnerung an die Rückgabe', 'overdue' => 'Überfällig', 'returned' => 'Rückgabe gebucht',
        'defect' => 'Mangel gemeldet', 'cancelled' => 'Storniert', 'extension_decided' => 'Verlängerung entschieden', 'chat' => 'Neue Chat-Nachricht',
    ];

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
            'mailTexts' => collect(self::MAIL_TEXTS)->map(fn (string $label, string $type) => ['type' => $type, 'label' => $label])->values(),
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
            'loans.page_days_after_close' => ['sometimes', 'required', 'integer', 'min:1', 'max:365'],
            'loans.remind_pickup_hours' => ['sometimes', 'required', 'integer', 'min:0', 'max:336'],
            'loans.remind_return_hours' => ['sometimes', 'required', 'integer', 'min:0', 'max:336'],
            'loans.overdue_days' => ['sometimes', 'required', 'string', 'max:40', 'regex:/^\s*\d{1,3}(\s*,\s*\d{1,3}){0,4}\s*$/'],
            'loans.pickup_place' => ['nullable', 'string', 'max:300'],
            'loans.mail_texts' => ['nullable', 'array'],
            'loans.mail_texts.*' => ['nullable', 'string', 'max:2000'],
            'reset_text' => ['boolean'],
        ], ['loans.number_prefix.regex' => 'Das Präfix hat 1 bis 6 Großbuchstaben oder Ziffern.', 'loans.overdue_days.regex' => 'Bis zu fünf Tage, mit Komma getrennt, z. B. 1, 3, 7.'], [
            'loans.lender' => 'Verleiher',
            'loans.place' => 'Ort der Unterschrift',
            'loans.buffer_hours' => 'Puffer',
            'loans.number_prefix' => 'Präfix',
            'loans.contract_template' => 'Vertragstext',
            'loans.page_days_after_close' => 'Tage nach Abschluss',
            'loans.remind_pickup_hours' => 'Erinnerung vor der Abholung',
            'loans.remind_return_hours' => 'Erinnerung vor der Rückgabe',
            'loans.overdue_days' => 'Überfällig-Stufen',
            'loans.pickup_place' => 'Abholort',
        ]);

        // the extra texts stay one array (only those with text), the rest are single keys
        $texts = array_filter(array_intersect_key($validated['loans']['mail_texts'] ?? [], self::MAIL_TEXTS), fn ($text) => filled($text));
        $values = Arr::dot(Arr::except($validated, ['reset_text', 'loans.mail_texts']));
        if (array_key_exists('mail_texts', $validated['loans'])) {
            $values['loans.mail_texts'] = array_map('trim', $texts);
        }
        // the text that comes with the module is not stored, so updates of it arrive
        if ($request->boolean('reset_text') || trim($values['loans.contract_template']) === trim(ContractTemplate::default())) {
            $values['loans.contract_template'] = null;
        }
        foreach (['loans.buffer_hours', 'loans.page_days_after_close', 'loans.remind_pickup_hours', 'loans.remind_return_hours'] as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = (int) $values[$key];
            }
        }
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
