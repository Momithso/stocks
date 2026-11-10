<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\ItemPhoto;
use App\Models\Location;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Deleting wrong entries from the history of an item.
 */
class HistoryTest extends TestCase
{
    public function test_a_condition_change_is_deleted_with_its_photos_and_the_condition_stays(): void
    {
        Storage::fake(ItemPhoto::DISK);
        $admin = $this->admin();
        $item = Item::factory()->create();
        $this->actingAs($admin)->post("/inventory/items/{$item->id}/condition", [
            'condition_id' => $this->conditionId('defekt'), 'reason' => 'Fehlmeldung', 'photos' => [UploadedFile::fake()->image('schaden.jpg')],
        ]);
        $change = $item->conditionChanges()->sole();
        $photo = $change->photos()->sole();

        $this->actingAs($admin)->delete("/inventory/items/{$item->id}/condition-changes/{$change->id}")->assertSessionHasNoErrors();

        $this->assertModelMissing($change);
        $this->assertModelMissing($photo);
        Storage::disk(ItemPhoto::DISK)->assertMissing($photo->path);
        $this->assertSame('defekt', $item->fresh()->condition->name);
    }

    public function test_deleted_entries_are_kept_in_the_audit_log(): void
    {
        $this->enableModelAuditing();
        $admin = $this->admin();
        $item = Item::factory()->create();
        $this->actingAs($admin)->post("/inventory/items/{$item->id}/condition", ['condition_id' => $this->conditionId('defekt'), 'reason' => 'Fehlmeldung']);
        $change = $item->conditionChanges()->sole();

        $this->actingAs($admin)->delete("/inventory/items/{$item->id}/condition-changes/{$change->id}");

        $this->assertSame(['condition' => 'defekt'], $item->audits()->where('event', 'conditionChanged')->sole()->new_values);
        $audit = $item->audits()->where('event', 'historyEntryDeleted')->sole();
        $this->assertStringStartsWith('Zustand gut → defekt vom ', $audit->old_values['history']);
        $this->assertStringEndsWith(': Fehlmeldung', $audit->old_values['history']);
        $this->assertSame($admin->id, $audit->user_id);
    }

    public function test_a_condition_change_is_deleted_when_its_photos_are_not_on_s3(): void
    {
        Storage::fake(ItemPhoto::DISK);
        $admin = $this->admin();
        $item = Item::factory()->create();
        $this->actingAs($admin)->post("/inventory/items/{$item->id}/condition", ['condition_id' => $this->conditionId('defekt'), 'reason' => 'kaputt']);
        $change = $item->conditionChanges()->sole();
        // a photo row whose file never reached S3
        $missing = new ItemPhoto(['condition_change_id' => $change->id, 'path' => 'items/1/fehlt.jpg']);
        $missing->item()->associate($item)->save();

        $this->actingAs($admin)->delete("/inventory/items/{$item->id}/condition-changes/{$change->id}")->assertSessionHasNoErrors();

        $this->assertModelMissing($change);
        $this->assertModelMissing($missing);
    }

    public function test_a_movement_is_deleted_and_the_place_stays(): void
    {
        $admin = $this->admin();
        $room = Location::factory()->create(['code' => 'EU']);
        $item = Item::factory()->create();
        $this->actingAs($admin)->post('/inventory/move', ['item_id' => $item->id, 'target_code' => 'EU']);
        $movement = $item->movements()->sole();

        $this->actingAs($admin)->delete("/inventory/items/{$item->id}/movements/{$movement->id}")->assertSessionHasNoErrors();

        $this->assertModelMissing($movement);
        $this->assertTrue($item->fresh()->location->is($room));
    }

    public function test_entries_of_other_items_can_not_be_deleted(): void
    {
        $admin = $this->admin();
        Location::factory()->create(['code' => 'EU']);
        $item = Item::factory()->create();
        $other = Item::factory()->create();
        $this->actingAs($admin)->post('/inventory/move', ['item_id' => $item->id, 'target_code' => 'EU']);
        $movement = $item->movements()->sole();

        $this->actingAs($admin)->delete("/inventory/items/{$other->id}/movements/{$movement->id}")->assertNotFound();
        $this->assertModelExists($movement);
    }

    public function test_deleting_entries_needs_the_permission(): void
    {
        $actor = $this->userWithRoles('Vorstand');
        Location::factory()->create(['code' => 'EU']);
        $item = Item::factory()->create();
        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $item->id, 'target_code' => 'EU']);
        $movement = $item->movements()->sole();

        $this->actingAs($actor)->delete("/inventory/items/{$item->id}/movements/{$movement->id}")->assertForbidden();
    }
}
