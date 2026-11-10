<?php

namespace App\Actions\Inventory;

use App\Enums\LocationType;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create a location or change it (code, name, parent).
 *
 * The level follows from the parent: an address has no parent, a room lies in an address and so
 * on. When the code or the parent changes, the full codes of the whole subtree are recomputed.
 */
class SaveLocation
{
    /**
     * @param  array{parent_id: int|null, code: string, name?: string|null, description?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function handle(?Location $location, array $data): Location
    {
        return DB::transaction(function () use ($location, $data) {
            $parent = $data['parent_id'] ? Location::findOrFail($data['parent_id']) : null;
            $type = $location?->type ?? ($parent ? $parent->type->childType() : LocationType::Address);

            $fitsParent = $parent ? $type !== null && $parent->type->childType() === $type : $type === LocationType::Address;
            if (! $fitsParent) {
                throw ValidationException::withMessages(['parent_id' => $this->levelMessage($type)]);
            }
            // the fixed levels also prevent a location from being moved below itself

            $code = mb_strtoupper($data['code']);
            $fullCode = Location::fullCodeOf($parent, $type, $code);
            if (Location::where('full_code', $fullCode)->whereKeyNot($location?->id)->exists()) {
                throw ValidationException::withMessages(['code' => "Die Kennziffer {$fullCode} gibt es schon."]);
            }

            $location ??= new Location(['type' => $type]);
            $codeChanged = $location->full_code !== $fullCode;
            $location->fill([
                'parent_id' => $parent?->id,
                'code' => $code,
                'name' => $data['name'] ?? null,
                'description' => $data['description'] ?? null,
                'full_code' => $fullCode,
            ])->save();

            if ($codeChanged) {
                $this->updateChildCodes($location);
            }

            return $location;
        });
    }

    /**
     * Recompute the full codes below a location, level by level.
     */
    private function updateChildCodes(Location $parent): void
    {
        foreach ($parent->children()->get() as $child) {
            // saved one by one, so the audit log shows every changed code
            $child->update(['full_code' => Location::fullCodeOf($parent, $child->type, $child->code)]);
            $this->updateChildCodes($child);
        }
    }

    /**
     * Message for a parent of the wrong level.
     */
    private function levelMessage(?LocationType $type): string
    {
        return match ($type) {
            null => 'In einem Fach gibt es keine weiteren Standorte.',
            LocationType::Address => 'Eine Adresse liegt in keinem anderen Standort.',
            LocationType::Room => 'Ein Raum liegt in einer Adresse.',
            LocationType::Shelf => 'Ein Lagerplatz liegt in einem Raum.',
            LocationType::Compartment => 'Ein Fach liegt in einem Lagerplatz.',
        };
    }
}
