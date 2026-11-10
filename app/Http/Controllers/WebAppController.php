<?php

namespace App\Http\Controllers;

use App\Support\Branding;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Web app: manifest and icons, so Stocks can be added to the home screen of a phone and runs in full
 * screen there. The icons are made from the logo of the organization. There is no offline mode.
 */
class WebAppController extends Controller
{
    /**
     * Sizes of the icons in pixels (180 is the size of iPhones).
     *
     * @var list<int>
     */
    public const ICON_SIZES = [180, 192, 512];

    /**
     * Deliver the manifest.
     */
    public function manifest(Settings $settings, Branding $branding): JsonResponse
    {
        $version = pathinfo((string) $branding->path('logo'), PATHINFO_FILENAME) ?: 'default';

        return response()->json([
            'name' => $settings->get('organization.name'),
            'short_name' => $settings->get('organization.short_name'),
            'start_url' => '/dashboard',
            'scope' => '/',
            'display' => 'fullscreen',
            'display_override' => ['fullscreen', 'standalone'],
            'orientation' => 'portrait',
            'background_color' => '#ffffff',
            'theme_color' => $settings->get('organization.accent_color'),
            'icons' => array_map(fn (int $size) => [
                'src' => route('web-app.icon', ['size' => $size, 'v' => $version], absolute: false),
                'sizes' => "{$size}x{$size}",
                'type' => 'image/png',
                'purpose' => 'any',
            ], self::ICON_SIZES),
        ], headers: ['Content-Type' => 'application/manifest+json']);
    }

    /**
     * Deliver an icon: the logo in the middle of a white square, or a box on the accent colour
     * without logo. Made once per logo, colour and size.
     */
    public function icon(Settings $settings, Branding $branding, int $size): Response
    {
        abort_unless(in_array($size, self::ICON_SIZES, true), 404);

        $accent = (string) $settings->get('organization.accent_color');
        $key = 'stocks.web-app-icon.'.md5($branding->path('logo').$accent.$size);

        $png = Cache::get($key);
        if ($png === null) {
            $png = $this->draw($size, $branding->contents('logo'), $accent);
            // without S3 the default icon is shown, but not kept
            if ($branding->path('logo') === null || $png !== null) {
                Cache::put($key, $png, now()->addWeek());
            }
        }

        return response($png ?? $this->draw($size, null, $accent), headers: [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }

    /**
     * Draw an icon as png.
     */
    private function draw(int $size, ?string $logo, string $accent): ?string
    {
        $image = imagecreatetruecolor($size, $size);
        $source = $logo !== null ? @imagecreatefromstring($logo) : false;

        if ($source !== false) {
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
            // the logo fills 80 %, so round masks of launchers do not cut it
            $scale = $size * 0.8 / max(imagesx($source), imagesy($source));
            $width = (int) round(imagesx($source) * $scale);
            $height = (int) round(imagesy($source) * $scale);
            imagecopyresampled($image, $source, intdiv($size - $width, 2), intdiv($size - $height, 2), 0, 0, $width, $height, imagesx($source), imagesy($source));
        } else {
            [$red, $green, $blue] = sscanf($accent, '#%02x%02x%02x') ?: [34, 148, 140];
            imagefill($image, 0, 0, imagecolorallocate($image, $red, $green, $blue));
            $this->drawBox($image, $size, imagecolorallocate($image, 255, 255, 255));
        }

        ob_start();
        imagepng($image);

        return ob_get_clean() ?: null;
    }

    /**
     * A simple box (front and lid) in the middle of the icon.
     */
    private function drawBox(\GdImage $image, int $size, int $color): void
    {
        $unit = $size / 100;
        $points = fn (array $coordinates) => array_map(fn (float $value) => (int) round($value * $unit), $coordinates);

        imagefilledpolygon($image, $points([24, 40, 34, 28, 66, 28, 76, 40]), $color);
        imagefilledrectangle($image, ...[...$points([28, 44, 72, 74]), $color]);
    }
}
