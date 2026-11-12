<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The model moved from the core into the module. The audit log stores the class name, so old
     * entries get the new one and stay readable as "Kiosk-Gerät".
     */
    public function up(): void
    {
        DB::table('audits')->where('auditable_type', 'App\\Models\\KioskDevice')->update(['auditable_type' => 'Modules\\Kiosk\\Models\\KioskDevice']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('audits')->where('auditable_type', 'Modules\\Kiosk\\Models\\KioskDevice')->update(['auditable_type' => 'App\\Models\\KioskDevice']);
    }
};
