<?php

namespace App\Support\Demo;

use App\Support\Labels\ShortLink;
use App\Support\QrCode;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Test label sheet of the demo data: A4 with 24 labels (3 × 8) with qr code, number, name and what
 * the label is for, and a second page with the check list (what should happen on a scan). With it
 * every flow can be tried with a camera or a hand scanner without printing labels first.
 *
 * The labels are chosen by the demo parts (DemoContext::label) and stored in the setting
 * "demo.labels". The codes are short links with the address of the instance, so a phone camera
 * opens them too. Drawn on the server as simple grid, not with the templates of the label editor
 * (those are drawn by the browser).
 */
class TestSheet
{
    /**
     * Labels on the first page.
     */
    public const LABELS = 24;

    public function __construct(private readonly Settings $settings, private readonly ShortLink $links) {}

    /**
     * Labels of the sheet with their qr codes.
     *
     * @return list<array{code: string, name: string, purpose: string, expect: string, qr: string}>
     */
    public function labels(): array
    {
        return array_map(fn (array $label) => [
            ...$label,
            'qr' => 'data:image/svg+xml;base64,'.base64_encode(QrCode::svg($this->links->url($label['code']), 200)),
        ], array_slice($this->settings->get('demo.labels') ?? [], 0, self::LABELS));
    }

    /**
     * The sheet as pdf content.
     */
    public function pdf(): string
    {
        return Pdf::setPaper('a4')->loadView('pdf.demo-sheet', [
            'labels' => $this->labels(),
            'organization' => $this->settings->get('organization.name'),
            'address' => $this->links->baseUrl(),
            'seededAt' => $this->settings->get('demo.seeded_at'),
        ])->output();
    }
}
