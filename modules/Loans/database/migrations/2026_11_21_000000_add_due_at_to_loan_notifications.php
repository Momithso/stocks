<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The time a reminder was about (pickup or return), so a new time (extension, other pickup)
| gets its reminders again (Modules\Loans\Support\Reminders).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_notifications', function (Blueprint $table) {
            $table->dateTime('due_at')->nullable()->after('stage');
        });

        // reminders sent before: about the times the loans have now, so nothing goes out twice
        foreach (['pickup_reminder' => 'pickup_at', 'return_reminder' => 'return_at', 'overdue' => 'return_at', 'overdue_staff' => 'return_at'] as $type => $column) {
            DB::table('loan_notifications')->where('type', $type)->update([
                'due_at' => DB::raw("(select {$column} from loans where loans.id = loan_notifications.loan_id)"),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('loan_notifications', function (Blueprint $table) {
            $table->dropColumn('due_at');
        });
    }
};
