<?php

namespace App\Http\Controllers;

use App\Support\Branding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Logo and favicon: deliver them to everybody, change them in the settings.
 */
class BrandingController extends Controller
{
    /**
     * Deliver an image. Without an uploaded favicon the default of public/ is used.
     */
    public function show(Branding $branding, string $kind): StreamedResponse|BinaryFileResponse
    {
        $path = $branding->path($kind);
        if ($path === null) {
            abort_unless($kind === 'favicon', 404);

            return response()->file(public_path('favicon.ico'));
        }

        // the url changes with every new image (see Branding::url), so it can be kept for a week
        return Storage::disk(Branding::DISK)->response($path, headers: ['Cache-Control' => 'public, max-age=604800']);
    }

    /**
     * Upload or remove logo and favicon. SVG is not allowed, because it can contain scripts.
     */
    public function update(Request $request, Branding $branding): RedirectResponse
    {
        $request->validate([
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'favicon' => ['nullable', 'file', 'mimes:png,ico', 'max:512'],
            'remove' => ['array'],
            'remove.*' => ['in:logo,favicon'],
        ]);

        foreach (array_keys(Branding::KINDS) as $kind) {
            if ($request->hasFile($kind)) {
                $branding->replace($kind, $request->file($kind));
            } elseif (in_array($kind, $request->input('remove', []), true)) {
                $branding->remove($kind);
            }
        }

        return back()->with('success', 'Logo und Symbol wurden gespeichert.');
    }
}
