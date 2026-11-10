<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Item;
use Tests\TestCase;

/**
 * Categories: two levels, deleting only when unused.
 */
class CategoryTest extends TestCase
{
    public function test_categories_have_two_levels(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'locations.manage');
        $technik = Category::factory()->create(['name' => 'Technik']);
        $kabel = Category::factory()->create(['name' => 'Kabel', 'parent_id' => $technik->id]);

        $this->actingAs($actor)->post('/inventory/categories', ['name' => 'XLR', 'parent_id' => $kabel->id])->assertSessionHasErrors('parent_id');
        $this->actingAs($actor)->put("/inventory/categories/{$technik->id}", ['name' => 'Technik', 'parent_id' => Category::factory()->create()->id])->assertSessionHasErrors('parent_id');
        $this->actingAs($actor)->post('/inventory/categories', ['name' => 'Licht', 'parent_id' => $technik->id])->assertSessionHasNoErrors();

        $this->assertSame('Technik › Licht', Category::firstWhere('name', 'Licht')->fullName());
    }

    public function test_a_category_is_only_deleted_when_unused(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'locations.manage');
        $category = Category::factory()->create();
        $item = Item::factory()->create(['category_id' => $category->id]);

        $this->actingAs($actor)->delete("/inventory/categories/{$category->id}")->assertSessionHasErrors('category');

        $item->delete();
        $this->actingAs($actor)->delete("/inventory/categories/{$category->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($category);
    }

    public function test_the_list_filters_by_category_with_its_sub_categories(): void
    {
        $actor = $this->userWithPermissions('inventory.view');
        $technik = Category::factory()->create();
        $kabel = Category::factory()->create(['parent_id' => $technik->id]);
        Item::factory()->create(['category_id' => $kabel->id]);
        Item::factory()->create(['category_id' => $technik->id]);
        Item::factory()->create();

        $this->actingAs($actor)->get("/inventory/items?category={$technik->id}")->assertInertia(fn ($page) => $page->has('items.data', 2));
        $this->actingAs($actor)->get("/inventory/items?category={$kabel->id}")->assertInertia(fn ($page) => $page->has('items.data', 1));
    }
}
