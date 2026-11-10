<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemPhoto;
use App\Models\ItemUnit;
use App\Support\Branding;
use App\Support\Labels\ShortLink;
use App\Support\QrCode;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\FontMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contents of a box as A4 list to print and put into the box: head with print logo, box and qr
 * code, then a table with check boxes, descriptions, notes and optionally the preview photos.
 *
 * As letter the first page follows DIN 5008, form B, for a DL window envelope: return line with
 * the address of the organization, a recipient typed in when downloading (without one the
 * organization itself), fold and hole marks on the left.
 */
class ContentsPdfController extends Controller
{
    /**
     * Size of photos in the pdf in pixels, enough for 2 cm at print resolution.
     */
    private const PHOTO_PIXELS = 240;

    /**
     * Width of the text in the address field in points: 85 mm, of which the first 5 mm stay empty so
     * the text lines up with the rest of the page at 25 mm.
     */
    private const RETURN_LINE_WIDTH = 80 / 25.4 * 72;

    /**
     * Height of the address zone below the note zone (27.3 mm) in points.
     */
    private const ADDRESS_ZONE_HEIGHT = 27.3 / 25.4 * 72;

    /**
     * Download the list. Query: "photos=1" adds the preview photos of the box and of every item,
     * "letter=1" lays out the first page as letter, "recipient" is its address (lines separated by
     * line breaks).
     */
    public function show(Request $request, Item $item, Settings $settings, Branding $branding, ShortLink $links): Response
    {
        abort_unless($item->is_container, 404);

        $validated = $request->validate(['recipient' => ['nullable', 'string', 'max:400']]);
        $withPhotos = $request->boolean('photos');
        $letter = $request->boolean('letter');
        $consumableUnits = ItemUnit::where('is_consumable', true)->pluck('name')->all();
        $address = $this->lines($settings->get('organization.address'));

        $pdf = Pdf::setPaper('a4');
        $metrics = $pdf->getDomPDF()->getFontMetrics();
        $sender = [$settings->get('organization.name'), ...$address];
        // without a recipient the letter is addressed to the organization itself
        $recipient = $this->lines($validated['recipient'] ?? null) ?: $sender;
        [$returnLine, $returnLineSize] = $this->returnLine($metrics, $sender);

        $pdf->loadView('pdf.box-contents', [
            'letter' => $letter,
            'recipient' => $recipient,
            'returnLine' => $returnLine,
            'returnLineSize' => $returnLineSize,
            'addressSize' => $this->addressSize($metrics, $recipient),
            'box' => $item,
            'rows' => $this->rows($item, $withPhotos),
            'withPhotos' => $withPhotos,
            'boxPhoto' => $withPhotos ? $this->photoOf($item->photos()->first()) : null,
            'consumableUnits' => $consumableUnits,
            'logo' => $this->pngDataUri($branding->contents($branding->printKind()), 400),
            'qr' => 'data:image/svg+xml;base64,'.base64_encode(QrCode::svg($links->url($item->number), 200)),
            'organization' => $settings->get('organization.name'),
            'address' => $address,
            'contact' => $settings->get('legal.contact_email'),
        ]);

        return $pdf->download('Inhalt '.Str::slug($item->number.' '.$item->name).'.pdf');
    }

    /**
     * Non-empty lines of a text.
     *
     * @return list<string>
     */
    private function lines(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $text))));
    }

    /**
     * Return line above the address in the window (one line, as DIN 5008 asks): organization and
     * address joined; a long one first gets a smaller font (down to 5 pt), then only the
     * organization with the last two address lines (street, city), at last it may wrap.
     *
     * @param  list<string>  $parts
     * @return array{0: string, 1: float} text and font size in points
     */
    private function returnLine(FontMetrics $metrics, array $parts): array
    {
        $font = $metrics->getFont('DejaVu Sans');
        $candidates = array_unique([
            implode(' · ', $parts),
            implode(' · ', [$parts[0], ...array_slice($parts, max(1, count($parts) - 2))]),
        ]);

        foreach ($candidates as $text) {
            foreach ([6.0, 5.5, 5.0] as $size) {
                if ($metrics->getTextWidth($text, $font, $size) <= self::RETURN_LINE_WIDTH) {
                    return [$text, $size];
                }
            }
        }

        return [end($candidates), 5.0];
    }

    /**
     * Font size of the address in the window: 10 pt, smaller when a line is too wide or there are too
     * many lines for the zone, so nothing wraps or is cut by the envelope.
     *
     * @param  list<string>  $lines
     */
    private function addressSize(FontMetrics $metrics, array $lines): float
    {
        $font = $metrics->getFont('DejaVu Sans');
        foreach ([10.0, 9.0, 8.0, 7.0] as $size) {
            $widest = max(array_map(fn (string $line) => $metrics->getTextWidth($line, $font, $size), $lines));
            if ($widest <= self::RETURN_LINE_WIDTH && count($lines) * $size * 1.2 <= self::ADDRESS_ZONE_HEIGHT) {
                return $size;
            }
        }

        return 6.0;
    }

    /**
     * Rows of the table: every active item with its depth, boxes followed by their contents.
     *
     * @return list<array{item: Item, depth: int, photo: string|null}>
     */
    private function rows(Item $box, bool $withPhotos, int $depth = 0): array
    {
        // the limit only protects against broken data
        if ($depth > 20) {
            return [];
        }

        $rows = [];
        foreach ($box->contents()->active()->with('photos')->get() as $item) {
            $rows[] = ['item' => $item, 'depth' => $depth, 'photo' => $withPhotos ? $this->photoOf($item->photos->first()) : null];
            if ($item->is_container) {
                $rows = [...$rows, ...$this->rows($item, $withPhotos, $depth + 1)];
            }
        }

        return $rows;
    }

    /**
     * Preview photo of an item as small jpeg, null without photo or while S3 is not reachable.
     */
    private function photoOf(?ItemPhoto $photo): ?string
    {
        if ($photo === null) {
            return null;
        }

        try {
            $content = Storage::disk(ItemPhoto::DISK)->get($photo->path);
        } catch (\Throwable) {
            return null;
        }

        return $this->scaled($content, self::PHOTO_PIXELS, 'jpeg');
    }

    /**
     * Image as png data uri; dompdf can not read every format (e.g. webp), so it is converted.
     */
    private function pngDataUri(?string $content, int $pixels): ?string
    {
        return $content !== null ? $this->scaled($content, $pixels, 'png') : null;
    }

    /**
     * Scale an image down to the given longest side and encode it as data uri.
     */
    private function scaled(string $content, int $pixels, string $format): ?string
    {
        $source = @imagecreatefromstring($content);
        if ($source === false) {
            return null;
        }

        $scale = min(1, $pixels / max(imagesx($source), imagesy($source)));
        $image = imagescale($source, max(1, (int) round(imagesx($source) * $scale)), max(1, (int) round(imagesy($source) * $scale)));
        if ($format === 'png') {
            imagesavealpha($image, true);
        }

        ob_start();
        $format === 'png' ? imagepng($image) : imagejpeg($image, null, 80);

        return "data:image/{$format};base64,".base64_encode((string) ob_get_clean());
    }
}
