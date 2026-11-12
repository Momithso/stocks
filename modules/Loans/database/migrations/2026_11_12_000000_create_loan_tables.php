<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. See documentation/LOANS.md for the rules behind the tables.
     */
    public function up(): void
    {
        // student councils a contact can belong to; FSR 1 to FSR 10 come with the module
        Schema::create('councils', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('number')->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        foreach (range(1, 10) as $number) {
            DB::table('councils')->insert(['number' => $number, 'name' => "FSR {$number}", 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        // borrowers and responsible persons, without account
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('email', 150)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('street', 150)->nullable();
            $table->string('zip', 10)->nullable();
            $table->string('city', 100)->nullable();
            // empty: private person
            $table->foreignId('council_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // a bracket around several loans
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('place', 150)->nullable();
            $table->foreignId('responsible_contact_id')->nullable()->constrained('contacts')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // a loan ("Buchung"), see Modules\Loans\Models\Loan
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('status', 20)->index();
            // external: lent to a contact with contract; internal: own use of the organization
            $table->string('kind', 10);
            $table->foreignId('borrower_contact_id')->nullable()->constrained('contacts')->restrictOnDelete();
            $table->foreignId('responsible_contact_id')->nullable()->constrained('contacts')->restrictOnDelete();
            // responsible member of an internal loan
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issuer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('pickup_at');
            $table->timestamp('return_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamp('defects_closed_at')->nullable();
            $table->text('defects_note')->nullable();
            $table->string('purpose', 200)->nullable();
            $table->text('notes')->nullable();
            // six digits, encrypted; wrong tries are counted and lock the pin at five
            $table->text('pack_pin')->nullable();
            $table->unsignedTinyInteger('pin_attempts')->default(0);
            // contract text that applied at the issue
            $table->longText('contract_text')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancelled_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['pickup_at', 'return_at']);
        });

        // signed documents with their frozen content and its sha-256 hash
        Schema::create('loan_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->json('snapshot')->nullable();
            $table->string('hash', 64)->nullable();
            // photo or scan of a paper signed on paper, on S3
            $table->string('path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // positions of a loan: items and boxes; the contents of a box become rows below it at the issue
        Schema::create('loan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('loan_items')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            // part of a bulk item that was split off at the issue
            $table->foreignId('issued_item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->string('condition_at_issue', 50)->nullable();
            // ticked off at the issue
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('checked_by_scan')->default(false);
            // ticked off at the return or the later inspection (before it is booked)
            $table->timestamp('return_checked_at')->nullable();
            $table->foreignId('return_checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('return_checked_by_scan')->default(false);
            $table->unsignedInteger('returned_quantity')->nullable();
            $table->string('return_state', 10)->nullable();
            $table->text('return_note')->nullable();
            // booked as returned, with the receipt it is on
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('return_document_id')->nullable()->constrained('loan_documents')->nullOnDelete();
            $table->timestamp('inspected_at')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('stowed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('loan_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_document_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->string('name', 150);
            // png as data uri, null when signed on paper
            $table->longText('image')->nullable();
            $table->timestamp('signed_at');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device', 255)->nullable();
            $table->string('document_hash', 64);
            $table->boolean('on_paper')->default(false);
        });

        // checks of a box with the pack pin on the public page of the box
        Schema::create('loan_box_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_item_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->json('ticks');
            $table->unsignedInteger('total');
            $table->timestamp('checked_at');
        });

        // the column of phase 1 gets its foreign key
        Schema::table('item_condition_changes', function (Blueprint $table) {
            $table->foreign('loan_id')->references('id')->on('loans')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('item_condition_changes', function (Blueprint $table) {
            $table->dropForeign(['loan_id']);
        });
        Schema::dropIfExists('loan_box_checks');
        Schema::dropIfExists('loan_signatures');
        Schema::dropIfExists('loan_items');
        Schema::dropIfExists('loan_documents');
        Schema::dropIfExists('loans');
        Schema::dropIfExists('events');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('councils');
    }
};
