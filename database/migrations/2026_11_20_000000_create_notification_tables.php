<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Push subscriptions and the notification settings of accounts, see
     * documentation/NOTIFICATIONS.md.
     */
    public function up(): void
    {
        // the table of laravel-notification-channels/webpush, with device and last use
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            // an account (App\Models\User) or a loan (its borrower page)
            $table->morphs('subscribable', 'push_subscriptions_subscribable_morph_idx');
            $table->string('endpoint', 1024)->charset('ascii')->unique();
            $table->string('public_key')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('content_encoding')->nullable();
            // short name of the device, e.g. "iPhone" or "Chrome auf Windows"
            $table->string('device', 60)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            // only the deviations from the defaults: {"loans.chat_staff": {"mail": false}}
            $table->json('notification_preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('notification_preferences'));
        Schema::dropIfExists('push_subscriptions');
    }
};
