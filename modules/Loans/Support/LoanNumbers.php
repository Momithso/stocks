<?php

namespace Modules\Loans\Support;

use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Numbers of loans: prefix, year and a counter per year, e.g. "B-2026-0042". The prefix is the
 * setting "loans.number_prefix". The counter shares the table of the item numbers.
 */
class LoanNumbers
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Take the next number. Must run inside a transaction, the counter row stays locked until
     * the transaction ends.
     */
    public function next(): string
    {
        $prefix = ($this->settings->get('loans.number_prefix') ?: 'B').'-'.now()->year;

        $value = DB::table('number_sequences')->where('prefix', $prefix)->lockForUpdate()->value('next_value');
        if ($value === null) {
            $value = 1;
            DB::table('number_sequences')->insert(['prefix' => $prefix, 'next_value' => 2]);
        } else {
            DB::table('number_sequences')->where('prefix', $prefix)->update(['next_value' => $value + 1]);
        }

        return $prefix.'-'.str_pad((string) $value, 4, '0', STR_PAD_LEFT);
    }
}
