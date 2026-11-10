<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemPhoto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Photos of items. They lie in a private S3 bucket and are delivered by Stocks, so the S3 server
 * does not need to be reachable for visitors.
 */
class ItemPhotoController extends Controller
{
    /**
     * Rules of an uploaded photo. SVG is not allowed, because it can contain scripts.
     *
     * @return list<string>
     */
    public static function fileRules(): array
    {
        return ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'];
    }

    /**
     * Deliver a photo. Browsers keep it for a day, photos never change under their url.
     */
    public function show(ItemPhoto $photo): StreamedResponse
    {
        return Storage::disk(ItemPhoto::DISK)->response($photo->path, headers: [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * Add photos to the gallery of an item.
     */
    public function store(Request $request, Item $item): RedirectResponse
    {
        $request->validate([
            'photos' => ['required', 'array'],
            'photos.*' => self::fileRules(),
        ]);

        $free = ItemPhoto::MAX_PER_ITEM - $item->photos()->count();
        if (count($request->file('photos')) > $free) {
            throw ValidationException::withMessages(['photos' => 'Ein Gegenstand hat höchstens '.ItemPhoto::MAX_PER_ITEM.' Fotos.']);
        }

        ItemPhoto::uploadAll($item, $request->file('photos'));

        return back()->with('success', 'Die Fotos wurden hochgeladen.');
    }

    /**
     * Make a photo the first one of the gallery (preview in lists).
     */
    public function cover(Item $item, ItemPhoto $photo): RedirectResponse
    {
        $photo->update(['sort' => (int) $item->photos()->min('sort') - 1]);

        return back();
    }

    /**
     * Delete a photo of the gallery.
     */
    public function destroy(Item $item, ItemPhoto $photo): RedirectResponse
    {
        $photo->deleteWithFile();

        return back()->with('success', 'Das Foto wurde gelöscht.');
    }
}
