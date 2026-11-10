<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\ItemCondition;
use Tests\TestCase;

/**
 * The list of conditions, managed by admins.
 */
class ConditionTest extends TestCase
{
    public function test_admins_manage_the_conditions(): void
    {
        $this->enableModelAuditing();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/inventory/conditions', ['name' => 'Wartung nötig', 'is_defect' => true])->assertSessionHasNoErrors();
        $condition = ItemCondition::firstWhere('name', 'Wartung nötig');
        $this->assertTrue($condition->is_defect);

        $this->actingAs($admin)->put("/inventory/conditions/{$condition->id}", ['name' => 'in Wartung'])->assertSessionHasNoErrors();
        $this->assertSame('in Wartung', $condition->fresh()->name);
        $this->assertFalse($condition->fresh()->is_defect);

        $this->actingAs($admin)->delete("/inventory/conditions/{$condition->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($condition);
        // the audit log keeps the deleted condition
        $this->assertDatabaseHas('audits', ['auditable_type' => ItemCondition::class, 'auditable_id' => $condition->id, 'event' => 'deleted']);
    }

    public function test_names_are_unique(): void
    {
        $this->actingAs($this->admin())->post('/inventory/conditions', ['name' => 'gut'])->assertSessionHasErrors('name');
    }

    public function test_a_condition_of_items_can_not_be_deleted(): void
    {
        $item = Item::factory()->create();

        $this->actingAs($this->admin())->delete("/inventory/conditions/{$item->condition_id}")->assertSessionHasErrors('condition');
        $this->assertModelExists($item->condition);
    }

    public function test_the_history_keeps_the_name_of_a_removed_condition(): void
    {
        $admin = $this->admin();
        $item = Item::factory()->create();
        $broken = ItemCondition::create(['name' => 'kaputt']);
        $this->actingAs($admin)->post("/inventory/items/{$item->id}/condition", ['condition_id' => $broken->id, 'reason' => 'runtergefallen']);
        $this->actingAs($admin)->post("/inventory/items/{$item->id}/condition", ['condition_id' => $this->conditionId('defekt'), 'reason' => 'doch defekt']);

        $this->actingAs($admin)->delete("/inventory/conditions/{$broken->id}")->assertSessionHasNoErrors();

        $this->assertDatabaseHas('item_condition_changes', ['item_id' => $item->id, 'from_condition' => 'gut', 'to_condition' => 'kaputt']);
    }

    public function test_only_admins_manage_conditions(): void
    {
        $this->actingAs($this->userWithRoles('Vorstand'))->get('/inventory/conditions')->assertForbidden();
        $this->actingAs($this->admin())->get('/inventory/conditions')->assertOk();
    }
}
