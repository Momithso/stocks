<?php

namespace App\Http\Controllers;

use App\Actions\Setup\InstallStocks;
use App\Models\User;
use App\Support\Modules\ModuleManifest;
use App\Support\Modules\ModuleRegistry;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setup wizard of a new instance. Only available until the setup is completed and only with the
 * setup token from the environment variable SETUP_TOKEN.
 */
class SetupController extends Controller
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Display the wizard.
     */
    public function show(ModuleRegistry $modules): Response
    {
        $this->ensureAvailable();

        return Inertia::render('Setup', [
            'hasToken' => filled(config('stocks.setup_token')),
            'startRoles' => array_keys(config('stocks.start_roles')),
            'modules' => array_values(array_map(
                fn (ModuleManifest $module) => ['key' => $module->key, 'name' => $module->name, 'description' => $module->description],
                $modules->all(),
            )),
            'defaults' => $this->settings->all(),
        ]);
    }

    /**
     * Set up the instance and log in the first admin.
     */
    public function store(Request $request, InstallStocks $installStocks): RedirectResponse
    {
        $this->ensureAvailable();

        $validated = $request->validate([
            'token' => ['required', 'string'],
            'organization.name' => ['required', 'string', 'max:100'],
            'organization.short_name' => ['required', 'string', 'max:30'],
            'organization.accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'legal.imprint_url' => ['nullable', 'url'],
            'legal.privacy_url' => ['nullable', 'url'],
            'legal.contact_email' => ['nullable', 'email'],
            'mail.from_name' => ['nullable', 'string', 'max:100'],
            'mail.from_address' => ['nullable', 'email'],
            'admin.firstname' => ['required', 'string', 'max:64'],
            'admin.lastname' => ['required', 'string', 'max:64'],
            'admin.email' => ['required', 'email'],
            'admin.password' => ['required', 'confirmed', Password::defaults()],
            'roles' => ['array'],
            'roles.*' => ['nullable', 'string', 'max:50'],
            'modules' => ['array'],
            'modules.*' => ['string'],
        ]);

        if (! hash_equals((string) config('stocks.setup_token'), $validated['token'])) {
            throw ValidationException::withMessages(['token' => 'Der Einrichtungsschlüssel ist falsch.']);
        }

        $admin = $installStocks->handle(
            settings: collect($validated)->only(['organization', 'legal', 'mail'])->dot()->all(),
            admin: collect($validated['admin'])->only(['firstname', 'lastname', 'email', 'password'])->all(),
            roleNames: $validated['roles'] ?? [],
            activeModules: $validated['modules'] ?? [],
        );

        Auth::login($admin);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Stocks ist eingerichtet.');
    }

    /**
     * The wizard only works while no admin exists, without a token it never works.
     */
    private function ensureAvailable(): void
    {
        abort_if($this->settings->isSetupCompleted() || User::exists(), 404);
    }
}
