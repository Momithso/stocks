<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\ItemPhoto;
use App\Models\Location;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Photos on S3 (faked), delivered by Stocks.
 */
class PhotoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ItemPhoto::DISK);
    }

    public function test_photos_are_stored_on_s3_and_delivered_with_permission(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit');
        $item = Item::factory()->create();

        $this->actingAs($actor)->post("/inventory/items/{$item->id}/photos", ['photos' => [UploadedFile::fake()->image('beamer.jpg')]])->assertSessionHasNoErrors();

        $photo = $item->photos()->sole();
        Storage::disk(ItemPhoto::DISK)->assertExists($photo->path);
        $this->assertStringStartsWith("items/{$item->id}/", $photo->path);

        $this->actingAs($actor)->get($photo->url())->assertOk();
        $this->actingAs($this->userWithPermissions())->get($photo->url())->assertForbidden();
    }

    public function test_photos_can_be_added_when_creating_an_item(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit');

        $this->actingAs($actor)->post('/inventory/items', [
            'name' => 'Beamer', 'kind' => 'single', 'condition_id' => $this->conditionId('gut'),
            'photos' => [UploadedFile::fake()->image('vorne.jpg'), UploadedFile::fake()->image('hinten.jpg')],
        ])->assertSessionHasNoErrors();

        $photos = Item::firstWhere('name', 'Beamer')->photos;
        $this->assertCount(2, $photos);
        Storage::disk(ItemPhoto::DISK)->assertExists($photos->first()->path);
    }

    public function test_no_item_is_created_when_s3_can_not_store_its_photos(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit');
        $disk = Mockery::mock(Storage::disk(ItemPhoto::DISK))->makePartial();
        $disk->shouldReceive('putFileAs')->andReturnFalse();
        Storage::set(ItemPhoto::DISK, $disk);

        $this->actingAs($actor)->post('/inventory/items', [
            'name' => 'Beamer', 'kind' => 'single', 'condition_id' => $this->conditionId('gut'),
            'photos' => [UploadedFile::fake()->image('vorne.jpg')],
        ])->assertSessionHasErrors('photos');

        $this->assertDatabaseCount('items', 0);
    }

    public function test_a_split_off_part_gets_copies_of_the_photos(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        Location::factory()->create(['code' => 'GO']);
        $tape = Item::factory()->bulk(10, 'Rollen')->create();
        [$photo] = ItemPhoto::uploadAll($tape, [UploadedFile::fake()->image('tape.jpg')]);

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $tape->id, 'target_code' => 'GO', 'quantity' => 4])->assertSessionHasNoErrors();

        $copy = Item::where('split_from_id', $tape->id)->sole()->photos()->sole();
        $this->assertNotSame($photo->path, $copy->path);
        Storage::disk(ItemPhoto::DISK)->assertExists([$photo->path, $copy->path]);

        // deleting the copy keeps the photo of the origin
        $copy->deleteWithFile();
        Storage::disk(ItemPhoto::DISK)->assertExists($photo->path);
    }

    public function test_only_images_up_to_five_megabytes_are_accepted(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit');
        $item = Item::factory()->create();

        $this->actingAs($actor)->post("/inventory/items/{$item->id}/photos", ['photos' => [UploadedFile::fake()->create('bild.svg', 10, 'image/svg+xml')]])->assertSessionHasErrors('photos.0');
        $this->actingAs($actor)->post("/inventory/items/{$item->id}/photos", ['photos' => [UploadedFile::fake()->image('gross.jpg')->size(6000)]])->assertSessionHasErrors('photos.0');
    }

    public function test_an_item_has_at_most_five_photos(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit');
        $item = Item::factory()->create();
        $photos = array_map(fn ($i) => UploadedFile::fake()->image("foto{$i}.jpg"), range(1, 6));

        $this->actingAs($actor)->post("/inventory/items/{$item->id}/photos", ['photos' => $photos])->assertSessionHasErrors('photos');
    }

    public function test_deleting_a_photo_deletes_the_file(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit');
        $item = Item::factory()->create();
        [$photo] = ItemPhoto::uploadAll($item, [UploadedFile::fake()->image('beamer.jpg')]);

        $this->actingAs($actor)->delete("/inventory/items/{$item->id}/photos/{$photo->id}")->assertSessionHasNoErrors();

        Storage::disk(ItemPhoto::DISK)->assertMissing($photo->path);
        $this->assertModelMissing($photo);
    }

    public function test_nothing_is_saved_when_s3_can_not_store_the_photos(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit', 'inventory.move');
        $item = Item::factory()->create();
        // a disk that stores the first file and fails on the second, as if S3 went away
        $disk = Mockery::mock(Storage::disk(ItemPhoto::DISK))->makePartial();
        $disk->shouldReceive('putFileAs')->andReturn('items/1/erstes.jpg', false);
        $disk->shouldReceive('delete')->once()->with(['items/1/erstes.jpg'])->andReturnTrue();
        Storage::set(ItemPhoto::DISK, $disk);

        $this->actingAs($actor)->post("/inventory/items/{$item->id}/photos", [
            'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ])->assertSessionHasErrors('photos');

        $this->assertDatabaseCount('item_photos', 0);
    }

    public function test_a_condition_change_is_not_saved_without_its_photos(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        $item = Item::factory()->create();
        $disk = Mockery::mock(Storage::disk(ItemPhoto::DISK))->makePartial();
        $disk->shouldReceive('putFileAs')->andReturnFalse();
        Storage::set(ItemPhoto::DISK, $disk);

        $this->actingAs($actor)->post("/inventory/items/{$item->id}/condition", [
            'condition_id' => $this->conditionId('defekt'), 'reason' => 'Gehäuse gebrochen', 'photos' => [UploadedFile::fake()->image('schaden.jpg')],
        ])->assertSessionHasErrors('photos');

        $this->assertDatabaseCount('item_condition_changes', 0);
        $this->assertSame('gut', $item->fresh()->condition->name);
    }

    public function test_photos_of_a_condition_change_are_not_in_the_gallery(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        $item = Item::factory()->create();

        $this->actingAs($actor)->post("/inventory/items/{$item->id}/condition", [
            'condition_id' => $this->conditionId('defekt'), 'reason' => 'Gehäuse gebrochen', 'photos' => [UploadedFile::fake()->image('schaden.jpg')],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $item->photos()->count());
        $this->assertSame(1, $item->conditionChanges()->sole()->photos()->count());
    }
}
