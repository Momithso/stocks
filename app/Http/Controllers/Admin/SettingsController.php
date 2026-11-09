<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings of the instance: organization, legal links, sender of mails.
 */
class SettingsController extends Controller
{
    /**
     * Display the settings.
     */
    public function edit(Settings $settings): Response
    {
        return Inertia::render('Admin/Settings', [
            'settings' => $settings->all(),
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
        ]);

        $settings->set(collect($validated)->dot()->all());

        return back()->with('success', 'Die Einstellungen wurden gespeichert.');
    }
}
