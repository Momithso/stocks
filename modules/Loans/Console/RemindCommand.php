<?php

namespace Modules\Loans\Console;

use Illuminate\Console\Command;
use Modules\Loans\Support\Reminders;

/**
 * Send the reminders of loans that are due (every 15 minutes by the scheduler). Sends only what
 * the log does not have yet, so running it twice is harmless.
 */
class RemindCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'loans:remind';

    /**
     * @var string
     */
    protected $description = 'Send the due reminders of loans (pickup, return, overdue)';

    public function handle(Reminders $reminders): int
    {
        $this->info($reminders->run().' Erinnerung(en) ausgelöst.');

        return self::SUCCESS;
    }
}
