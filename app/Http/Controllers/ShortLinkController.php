<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\ItemPhoto;
use App\Models\Location;
use App\Models\ReservedNumber;
use App\Support\Extensions\ItemExtensions;
use App\Support\Inventory\CodeLookup;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Short links in the codes of labels (/s/{code}). Logged in members land on the page of the item,
 * box or location; everybody else sees a public view that helps to bring an item back or to pack a
 * box. Place, borrower and history stay hidden. See documentation/LABELS.md.
 */
class ShortLinkController extends Controller
{
    /**
     * Minutes a link to a photo of the public view works.
     */
    private const PHOTO_LINK_MINUTES = 60;

    public function __construct(private readonly CodeLookup $codes, private readonly Settings $settings, private readonly ItemExtensions $extensions) {}

    /**
     * Open a scanned code.
     */
    public function show(Request $request, string $code): Response|RedirectResponse
    {
        $found = $this->codes->find($code);
        $reserved = $found ? null : ReservedNumber::findOpen($code);
        $user = $request->user();

        // "Anmelden" of the public view: after the login the link opens the full page
        if ($user === null && $request->boolean('login')) {
            return redirect()->guest(route('login'));
        }

        if ($user?->can('inventory.view')) {
            if ($found instanceof Item) {
                return to_route('inventory.items.show', $found);
            }
            if ($found instanceof Location) {
                return to_route('inventory.locations.show', $found);
            }
            if ($reserved && $user->can('inventory.edit')) {
                return to_route('inventory.items.create', ['number' => $reserved->number]);
            }
        }

        return Inertia::render('Public/Show', [
            'code' => $code,
            // not "organization": that is a shared prop of every page
            'owner' => $this->settings->get('organization.name'),
            'contact' => $this->settings->get('legal.contact_email'),
            'loggedIn' => $user !== null,
            ...$this->view($found, $reserved, $code, $user?->can('inventory.edit') ?? false),
        ])->toResponse($request)->withHeaders(['X-Robots-Tag' => 'noindex, nofollow']);
    }

    /**
     * Deliver a photo of the public view. The link is signed and expires, so photos can not be
     * fetched by trying ids.
     */
    public function photo(ItemPhoto $photo): StreamedResponse
    {
        return Storage::disk(ItemPhoto::DISK)->response($photo->path, headers: [
            'Cache-Control' => 'private, max-age=3600',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * Props of the public view by what was scanned.
     *
     * @return array<string, mixed>
     */
    private function view(Item|Location|null $found, ?ReservedNumber $reserved, string $code, bool $canCreate): array
    {
        if ($found instanceof Location) {
            return ['kind' => 'location', 'location' => ['code' => $found->full_code, 'name' => $found->name]];
        }

        if ($found instanceof Item && $found->isRetired()) {
            return ['kind' => 'retired', 'item' => ['number' => $found->number, 'name' => $found->name]];
        }

        if ($found instanceof Item) {
            $found->load('photos');
            $showContents = $found->is_container && $found->public_contents;

            return [
                'kind' => $showContents ? 'box' : 'item',
                'item' => [
                    'number' => $found->number,
                    'name' => $found->name,
                    'photo_url' => $this->photoUrl($found->photos->first()),
                ],
                'contents' => $showContents ? $this->contents($found) : [],
                // sections of modules, e.g. the pack pin of a loan
                'sections' => $showContents ? $this->extensions->publicBoxSections($found) : [],
            ];
        }

        if ($reserved) {
            return ['kind' => 'reserved', 'item' => ['number' => $reserved->number]];
        }

        return [
            'kind' => 'unknown',
            'message' => $this->codes->notFoundMessage($code),
            'createUrl' => $canCreate ? route('inventory.items.create', absolute: false) : null,
        ];
    }

    /**
     * Active contents of a box as tree with quantities and photos.
     *
     * @return list<array<string, mixed>>
     */
    private function contents(Item $box, int $depth = 0): array
    {
        // the limit only protects against broken data
        if ($depth > 20) {
            return [];
        }

        return $box->contents()->active()->with('photos')->get()->map(fn (Item $item) => [
            ...$item->only(['number', 'name', 'quantity', 'unit', 'is_container']),
            'kind' => $item->kind->value,
            'photo_url' => $this->photoUrl($item->photos->first()),
            'contents' => $item->is_container ? $this->contents($item, $depth + 1) : [],
        ])->all();
    }

    /**
     * Signed link of a photo, null without photo.
     */
    private function photoUrl(?ItemPhoto $photo): ?string
    {
        return $photo
            ? URL::temporarySignedRoute('short-link.photo', now()->addMinutes(self::PHOTO_LINK_MINUTES), $photo, absolute: false)
            : null;
    }
}
