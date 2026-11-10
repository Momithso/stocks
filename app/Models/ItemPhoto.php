<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Photo of an item, stored on the disk "s3" and delivered by App\Http\Controllers\Inventory\ItemPhotoController.
 *
 * @property int $id
 * @property int $item_id
 * @property int|null $condition_change_id photo of a condition change instead of the gallery
 * @property string $path
 * @property int $sort
 */
class ItemPhoto extends Model
{
    /**
     * Disk of all photos.
     *
     * @var string
     */
    public const DISK = 's3';

    /**
     * Photos per item in the gallery.
     *
     * @var int
     */
    public const MAX_PER_ITEM = 5;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'condition_change_id',
        'path',
        'sort',
    ];

    /**
     * Store uploaded photos on S3 under "items/<id>/<random name>".
     *
     * All files are stored before any row is created. If S3 can not store one of them (e.g. it is
     * not reachable), the files stored so far are removed again and nothing is saved.
     *
     * @param  list<UploadedFile>  $files
     * @param  ItemConditionChange|null  $conditionChange  photos of a condition change instead of the gallery
     * @return list<self>
     *
     * @throws ValidationException
     */
    public static function uploadAll(Item $item, array $files, ?ItemConditionChange $conditionChange = null): array
    {
        $paths = [];
        foreach ($files as $file) {
            $path = $file->store("items/{$item->id}", self::DISK);
            if ($path === false) {
                if ($paths) {
                    Storage::disk(self::DISK)->delete($paths);
                }

                throw ValidationException::withMessages(['photos' => 'Die Fotos konnten nicht gespeichert werden, der Speicher ist gerade nicht erreichbar. Versuch es später noch einmal.']);
            }
            $paths[] = $path;
        }

        $sort = (int) self::where('item_id', $item->id)->max('sort');

        return array_map(function (string $path) use ($item, $conditionChange, &$sort) {
            $photo = new self(['condition_change_id' => $conditionChange?->id, 'path' => $path, 'sort' => ++$sort]);
            $photo->item()->associate($item)->save();

            return $photo;
        }, $paths);
    }

    /**
     * Copy the photo (with its file) to another item, e.g. to a split off part. Returns null if
     * S3 could not copy the file; the caller goes on without this photo.
     */
    public function copyTo(Item $item): ?self
    {
        $path = "items/{$item->id}/".basename($this->path);
        if (! Storage::disk(self::DISK)->copy($this->path, $path)) {
            return null;
        }

        $photo = new self(['path' => $path, 'sort' => $this->sort]);
        $photo->item()->associate($item)->save();

        return $photo;
    }

    /**
     * Delete the photo and its file.
     */
    public function deleteWithFile(): void
    {
        $this->delete();
        Storage::disk(self::DISK)->delete($this->path);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Url of the photo in Stocks (the bucket stays private).
     */
    public function url(): string
    {
        return route('inventory.photos.show', $this, absolute: false);
    }
}
