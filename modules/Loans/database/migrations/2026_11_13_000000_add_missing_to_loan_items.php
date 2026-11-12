<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What went missing at a return ("vermisst") and how it ended: found again or lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_items', function (Blueprint $table) {
            // the single item, or the part split off for missing pieces of a bulk item
            $table->foreignId('missing_item_id')->nullable()->after('stowed_at')->constrained('items')->nullOnDelete();
            $table->string('missing_resolution', 10)->nullable()->after('missing_item_id');
            $table->timestamp('missing_resolved_at')->nullable()->after('missing_resolution');
        });
    }

    public function down(): void
    {
        Schema::table('loan_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('missing_item_id');
            $table->dropColumn(['missing_resolution', 'missing_resolved_at']);
        });
    }
};
