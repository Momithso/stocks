<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Pages and components that also run on the kiosk must build their addresses with url() of
 * useSurface(), otherwise a request leaves the kiosk (documentation/KIOSK.md). Fixed paths are
 * only allowed where the kiosk takes another branch (links to pages the kiosk does not have);
 * their number per file is listed here, so a new fixed path makes this test fail.
 */
class SurfacePathsTest extends TestCase
{
    /**
     * Files used on the kiosk and how many fixed paths they may keep for the pc.
     *
     * @var array<string, int>
     */
    private const FILES = [
        'resources/js/pages/Inventory/Scan.vue' => 0,
        'resources/js/pages/Labels/Print.vue' => 1, // "Als Vorlage speichern", hidden on the kiosk
        'resources/js/components/ui/Flash.vue' => 0,
        'resources/js/components/inventory/CameraScanner.vue' => 0,
        'resources/js/components/inventory/ConditionModal.vue' => 0,
        'resources/js/components/inventory/ConsumeModal.vue' => 0,
        'resources/js/components/inventory/PlaceInput.vue' => 0,
        'resources/js/components/inventory/PlaceLink.vue' => 2, // plain text on the kiosk
        'resources/js/components/inventory/ContentsTree.vue' => 1, // plain text on the kiosk
        'resources/js/labels/render.ts' => 0,
        'modules/Example/resources/js/pages/Index.vue' => 0,
        'modules/Loans/resources/js/pages/Kiosk.vue' => 0,
        'modules/Loans/resources/js/pages/Loans/Issue.vue' => 1, // "Zur Buchung", "Zur Liste" on the kiosk
        'modules/Loans/resources/js/pages/Loans/Return.vue' => 1, // the same
        'modules/Loans/resources/js/pages/Returns.vue' => 3, // loan titles and items: a window on the kiosk
        'modules/Loans/resources/js/components/LoanModal.vue' => 0,
        'resources/js/components/inventory/ItemInfoModal.vue' => 0,
        'modules/Loans/resources/js/components/ContactPicker.vue' => 0,
        'modules/Loans/resources/js/components/ContactForm.vue' => 0,
        'modules/Loans/resources/js/components/ScanInput.vue' => 0,
        'modules/Loans/resources/js/components/SignaturePad.vue' => 0,
        'modules/Loans/resources/js/components/StatusBadge.vue' => 0,
    ];

    public function test_pages_of_the_kiosk_build_their_addresses_with_url(): void
    {
        foreach (self::FILES as $file => $allowed) {
            // prettier may break the line after "url("
            $source = preg_replace('/url\(\s+/', 'url(', file_get_contents(base_path($file)));
            // a string or template literal that starts with a path of the app, not inside url(…)
            preg_match_all('#(?<!url\()[`"\'](/(?:inventory|labels|loans|kiosk|admin|example)\b)#', $source, $matches);

            $this->assertCount($allowed, $matches[1], "{$file} has fixed paths: ".implode(', ', $matches[1]).'. Use url() of useSurface().');

            // url() is no global: a file that calls it must take it from useSurface()
            if (preg_match('/[^\w.]url\(/', $source)) {
                $this->assertMatchesRegularExpression('/\{[^}]*\burl\b[^}]*\}\s*=\s*useSurface\(\)|useSurface\(\)\.url\(/', $source, "{$file} calls url() without taking it from useSurface().");
            }
        }
    }
}
