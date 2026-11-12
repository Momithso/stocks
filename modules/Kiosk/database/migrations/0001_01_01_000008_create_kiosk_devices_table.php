<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // tablets that may open the kiosk, see Modules\Kiosk\Models\KioskDevice
        Schema::create('kiosk_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            // encrypted, so the pairing url can be shown again
            $table->text('token');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kiosk_devices');
    }
};
