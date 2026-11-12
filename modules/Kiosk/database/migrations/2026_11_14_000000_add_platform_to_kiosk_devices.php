<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform of a tablet (Modules\Kiosk\Enums\KioskPlatform); existing tablets get "other",
     * which behaves as before.
     */
    public function up(): void
    {
        Schema::table('kiosk_devices', function (Blueprint $table) {
            $table->string('platform', 20)->default('other')->after('token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kiosk_devices', fn (Blueprint $table) => $table->dropColumn('platform'));
    }
};
