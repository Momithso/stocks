<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partial issue: every row knows when it left and with which material list; what did not leave
 * yet is the rest of the loan. A defect found at the issue is documented on the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_items', function (Blueprint $table) {
            $table->timestamp('issued_at')->nullable()->after('checked_by_scan');
            $table->foreignId('issue_document_id')->nullable()->after('issued_at')->constrained('loan_documents')->nullOnDelete();
            $table->string('issue_state', 10)->nullable()->after('issue_document_id');
            $table->text('issue_note')->nullable()->after('issue_state');
        });

        // loans issued before: all their rows left with the first material list
        DB::table('loan_items')
            ->whereIn('loan_id', DB::table('loans')->whereNotNull('issued_at')->select('id'))
            ->update([
                'issued_at' => DB::raw('(select issued_at from loans where loans.id = loan_items.loan_id)'),
                'issue_document_id' => DB::raw("(select min(id) from loan_documents where loan_documents.loan_id = loan_items.loan_id and loan_documents.type = 'list')"),
            ]);
    }

    public function down(): void
    {
        Schema::table('loan_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('issue_document_id');
            $table->dropColumn(['issued_at', 'issue_state', 'issue_note']);
        });
    }
};
