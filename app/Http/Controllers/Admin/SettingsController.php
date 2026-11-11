<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Branding;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings of the instance: organization, legal links, item numbers, labels. Mail has its own page
 * (MailSettingsController).
 */
class SettingsController extends Controller
{
    /**
     * Display the settings.
     */
    public function edit(Settings $settings, Branding $branding): Response
    {
        return Inertia::render('Admin/Settings', [
            // the mail password never leaves the server
            'settings' => Arr::except($settings->all(), ['mail.password']),
            'appUrl' => config('app.url'),
            'branding' => [
                'logo' => $branding->url('logo'),
                'favicon' => $settings->get('organization.favicon_path') ? $branding->url('favicon') : null,
                'print_logo' => $branding->url('print_logo'),
            ],
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
            'organization.address' => ['nullable', 'string', 'max:300'],
            'legal.imprint_url' => ['nullable', 'url'],
            'legal.privacy_url' => ['nullable', 'url'],
            'legal.contact_email' => ['nullable', 'email'],
            // new numbers only, existing numbers never change
            'inventory.number_prefix' => ['nullable', 'regex:/^[A-Z0-9]{1,6}$/'],
            // address in the codes of labels; old labels keep the old address
            'labels.base_url' => ['nullable', 'url:http,https', 'max:100'],
        ], ['inventory.number_prefix.regex' => 'Das Präfix hat 1 bis 6 Großbuchstaben oder Ziffern.']);

        $settings->set(collect($validated)->dot()->all());

        return back()->with('success', 'Die Einstellungen wurden gespeichert.');
    }
}
