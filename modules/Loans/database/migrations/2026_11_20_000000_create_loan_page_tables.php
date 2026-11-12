<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Borrower page, chat, extension requests and the log of notifications of
     * loans, see documentation/LOANS.md.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            // the key of the link is computed from APP_KEY, loan and this version (Support\LoanPage);
            // a new link raises it, so the old one is invalid at once
            $table->unsignedInteger('access_version')->default(1);
            $table->timestamp('access_blocked_at')->nullable();
            // settings of the borrower page, only deviations from the defaults
            $table->json('notification_preferences')->nullable();
        });

        Schema::table('loan_items', function (Blueprint $table) {
            // ticked off on the checklist of the borrower page (a help for packing, nothing more)
            $table->timestamp('borrower_checked_at')->nullable();
        });

        Schema::create('loan_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            // "contact" (borrower page), "user" (member) or "system"
            $table->string('sender', 10);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name', 150);
            $table->text('body');
            $table->timestamp('read_by_staff_at')->nullable();
            $table->timestamp('read_by_contact_at')->nullable();
            $table->timestamps();
            $table->index(['loan_id', 'id']);
        });

        Schema::create('loan_extension_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->dateTime('requested_return_at');
            $table->text('note')->nullable();
            // "open", "accepted" or "declined"
            $table->string('status', 10)->default('open');
            $table->text('answer')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        // one row per receiver and channel; reminders look here so nothing is sent twice
        Schema::create('loan_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->unsignedTinyInteger('stage')->nullable();
            // "mail" or "push"
            $table->string('channel', 10);
            $table->string('recipient', 190);
            // null: sent automatically
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at');
            $table->index(['loan_id', 'type', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_notifications');
        Schema::dropIfExists('loan_extension_requests');
        Schema::dropIfExists('loan_messages');
        Schema::table('loan_items', fn (Blueprint $table) => $table->dropColumn('borrower_checked_at'));
        Schema::table('loans', fn (Blueprint $table) => $table->dropColumn(['access_version', 'access_blocked_at', 'notification_preferences']));
    }
};
