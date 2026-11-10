<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Logo and favicon of the instance. The files lie on S3 (like the photos of items); the settings
 * keep their paths. App\Http\Controllers\BrandingController delivers them, also to guests (login
 * page, browser tab).
 */
class Branding
{
    /**
     * Disk of the files.
     *
     * @var string
     */
    public const DISK = 's3';

    /**
     * Images that can be changed, with their setting.
     *
     * @var array<string, string>
     */
    public const KINDS = [
        'logo' => 'organization.logo_path',
        'favicon' => 'organization.favicon_path',
    ];

    public function __construct(private readonly Settings $settings) {}

    /**
     * Path of an image on S3, null while the default is used.
     */
    public function path(string $kind): ?string
    {
        return $this->settings->get(self::KINDS[$kind]);
    }

    /**
     * Url of an image, null for a logo that was never uploaded. The file name is part of the url,
     * so browsers load a new image right away although they keep images for a week.
     */
    public function url(string $kind): ?string
    {
        $path = $this->path($kind);
        if ($path === null) {
            return $kind === 'favicon' ? '/favicon.ico' : null;
        }

        return route('branding.show', ['kind' => $kind, 'v' => pathinfo($path, PATHINFO_FILENAME)], absolute: false);
    }

    /**
     * Replace an image with an uploaded file.
     *
     * @throws ValidationException
     */
    public function replace(string $kind, UploadedFile $file): void
    {
        $path = $file->store('branding', self::DISK);
        if ($path === false) {
            throw ValidationException::withMessages([$kind => 'Das Bild konnte nicht gespeichert werden, der Speicher ist gerade nicht erreichbar.']);
        }

        $this->remove($kind);
        $this->settings->set([self::KINDS[$kind] => $path]);
    }

    /**
     * Go back to the default image. A file S3 can not delete is left behind.
     */
    public function remove(string $kind): void
    {
        if ($old = $this->path($kind)) {
            Storage::disk(self::DISK)->delete($old);
            $this->settings->set([self::KINDS[$kind] => null]);
        }
    }
}
