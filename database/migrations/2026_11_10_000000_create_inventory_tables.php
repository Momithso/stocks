<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. See documentation/INVENTORY.md for the rules behind the tables.
     */
    public function up(): void
    {
        // tree of address, room, shelf and compartment, see App\Models\Location
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('locations')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('code', 20);
            $table->string('name', 100)->nullable();
            $table->text('description')->nullable();
            // codes of the path, e.g. "EU-014-R03.4"; recomputed when a location above changes.
            // Unique, so a code exists only once below the same parent.
            $table->string('full_code', 100)->unique();
            $table->timestamps();
        });

        // two levels: category and sub category
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name', 100);
            $table->timestamps();
        });

        // conditions of items, managed by admins (permission conditions.manage)
        Schema::create('item_conditions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            // the condition means a damage: shown in red, later not lendable
            $table->boolean('is_defect')->default(false);
            $table->timestamps();
        });
        DB::table('item_conditions')->insert([
            ['name' => 'neu', 'is_defect' => false, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'gut', 'is_defect' => false, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'gebraucht', 'is_defect' => false, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'defekt', 'is_defect' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // units of bulk items, managed by admins; items store the name (see App\Models\ItemUnit)
        Schema::create('item_units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 20)->unique();
            // consumables (tape, cable ties) can be marked as used up in a few taps
            $table->boolean('is_consumable')->default(false);
            $table->timestamps();
        });
        foreach (['Stück' => false, 'Meter' => false, 'Rollen' => true, 'Packungen' => true, 'Sets' => false] as $name => $isConsumable) {
            DB::table('item_units')->insert(['name' => $name, 'is_consumable' => $isConsumable, 'created_at' => now(), 'updated_at' => now()]);
        }

        // everything in the inventory, boxes included, see App\Models\Item
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 10);
            $table->unsignedInteger('quantity')->default(1);
            $table->string('unit', 20)->nullable();
            $table->boolean('is_container')->default(false);
            $table->boolean('public_contents')->default(true);
            $table->foreignId('condition_id')->constrained('item_conditions')->restrictOnDelete();
            $table->text('notes')->nullable();
            // current place and home: a location or a box, at most one of each pair is set
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->foreignId('container_id')->nullable()->constrained('items')->restrictOnDelete();
            $table->foreignId('home_location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->foreignId('home_container_id')->nullable()->constrained('items')->restrictOnDelete();
            $table->foreignId('split_from_id')->nullable()->constrained('items')->nullOnDelete();
            $table->timestamp('retired_at')->nullable();
            $table->string('retired_reason', 20)->nullable();
            $table->text('retired_note')->nullable();
            $table->timestamps();
        });

        // changes of the condition with their reason, see App\Actions\Inventory\ChangeItemCondition
        Schema::create('item_condition_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            // names, not ids: the history stays readable when a condition is renamed or removed
            $table->string('from_condition', 50);
            $table->string('to_condition', 50);
            $table->text('reason');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // loan the change belongs to; the foreign key follows with the loan module (phase 3)
            $table->unsignedBigInteger('loan_id')->nullable()->index();
            $table->timestamp('created_at')->nullable();
        });

        // photos on S3; photos of a condition change are not shown in the gallery
        Schema::create('item_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('condition_change_id')->nullable()->constrained('item_condition_changes')->cascadeOnDelete();
            $table->string('path');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // log of every change of the current place; never changed afterwards
        Schema::create('item_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->unsignedInteger('quantity');
            $table->foreignId('from_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('from_container_id')->nullable()->constrained('items')->nullOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('to_container_id')->nullable()->constrained('items')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // counter of the item numbers per prefix, locked while a number is taken
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->string('prefix', 10)->primary();
            $table->unsignedInteger('next_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('item_movements');
        Schema::dropIfExists('item_photos');
        Schema::dropIfExists('item_condition_changes');
        Schema::dropIfExists('items');
        Schema::dropIfExists('item_conditions');
        Schema::dropIfExists('item_units');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('locations');
    }
};
