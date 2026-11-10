<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Branding;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings of the instance: organization, legal links, sender of mails, item numbers.
 */
class SettingsController extends Controller
{
    /**
     * Display the settings.
     */
    public function edit(Settings $settings, Branding $branding): Response
    {
        return Inertia::render('Admin/Settings', [
            'settings' => $settings->all(),
            'branding' => ['logo' => $branding->url('logo'), 'favicon' => $settings->get('organization.favicon_path') ? $branding->url('favicon') : null],
        ]);
    }

    /**
     * Save the settings.
     */
    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'organization.name' => ['required', 'string', 'max:100'],
            'organization.short_name' => ['required', 'string', 'max:30'],
            'organization.accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'legal.imprint_url' => ['nullable', 'url'],
            'legal.privacy_url' => ['nullable', 'url'],
            'legal.contact_email' => ['nullable', 'email'],
            'mail.from_name' => ['nullable', 'string', 'max:100'],
            'mail.from_address' => ['nullable', 'email'],
            // new numbers only, existing numbers never change
            'inventory.number_prefix' => ['nullable', 'regex:/^[A-Z0-9]{1,6}$/'],
        ], ['inventory.number_prefix.regex' => 'Das Präfix hat 1 bis 6 Großbuchstaben oder Ziffern.']);

        $settings->set(collect($validated)->dot()->all());

        return back()->with('success', 'Die Einstellungen wurden gespeichert.');
    }
}
