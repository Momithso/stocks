<?php

namespace Modules\Loans\Support;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Modules\Loans\Enums\LoanKind;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Loan;
use Modules\Loans\Notifications\LoanConfirmed;
use Modules\Loans\Notifications\LoanEventNotification;
use Modules\Loans\Notifications\LoanIssued;
use Modules\Loans\Notifications\LoanOverdue;
use Modules\Loans\Notifications\PickupReminder;
use Modules\Loans\Notifications\ReturnReminder;

/**
 * Reminders of loans: before the pickup, before the return and overdue in stages. "loans:remind"
 * runs every 15 minutes and sends only what the log (loan_notifications) does not have yet, so
 * running it twice is harmless. Every reminder first writes a row "ausgelöst" (channel "trigger"),
 * also when nobody gets it because everything is switched off. The same messages can be sent by
 * hand from the loan page.
 */
class Reminders
{
    /**
     * Messages that can be sent by hand, with their name.
     *
     * @var array<string, string>
     */
    public const MANUAL = [
        'confirmed' => 'Buchungsbestätigung mit Link und Pack-PIN',
        'pickup_reminder' => 'Erinnerung an die Abholung',
        'issued' => 'Ausgabe mit Vertrag und Materialliste',
        'return_reminder' => 'Erinnerung an die Rückgabe',
        'overdue' => 'Überfällig',
    ];

    /**
     * False while run(send: false) only marks what is due.
     */
    private bool $sending = true;

    public function __construct(private readonly Settings $settings, private readonly LoanNotifier $notifier) {}

    /**
     * Send what is due. Returns the number of reminders sent. Without $send the due reminders are
     * only marked in the log as triggered (demo data: nothing goes out right after seeding).
     */
    public function run(bool $send = true): int
    {
        $this->sending = $send;
        $sent = 0;

        $pickupHours = (int) $this->settings->get('loans.remind_pickup_hours', 24);
        if ($pickupHours > 0) {
            foreach ($this->external()->where('status', LoanStatus::Confirmed)->whereBetween('pickup_at', [now(), now()->addHours($pickupHours)])->get() as $loan) {
                // confirmed within the time of the reminder: the confirmation just told the time
                if ($loan->confirmed_at->gte($loan->pickup_at->copy()->subHours($pickupHours))) {
                    continue;
                }
                $sent += (int) $this->once($loan, new PickupReminder($loan));
            }
        }

        $returnHours = (int) $this->settings->get('loans.remind_return_hours', 24);
        if ($returnHours > 0) {
            $returning = $this->external()->where('status', LoanStatus::Issued)->whereBetween('return_at', [now(), now()->addHours($returnHours)])
                ->whereHas('loanItems', fn (Builder $query) => $query->whereNotNull('issued_at')->whereNull('returned_at'));
            foreach ($returning->get() as $loan) {
                $sent += (int) $this->once($loan, new ReturnReminder($loan));
            }
        }

        // overdue: the highest stage that is due and not sent yet; never for loans not picked up
        foreach ($this->external()->overdue()->get() as $loan) {
            $stage = $this->stageOf($loan);
            if ($stage > 0 && $this->once($loan, new LoanOverdue($loan, $stage))) {
                $sent++;
                // from the second stage the organization knows too
                if ($stage >= 2) {
                    $this->once($loan, new LoanOverdue($loan, $stage, toStaff: true));
                }
            }
        }

        return $sent;
    }

    /**
     * Send a message by hand. Overdue gets the current stage (at least 1).
     */
    public function sendByHand(Loan $loan, string $type, User $actor): void
    {
        $notification = match ($type) {
            'confirmed' => new LoanConfirmed($loan, $actor->id),
            'pickup_reminder' => new PickupReminder($loan, $actor->id),
            'issued' => new LoanIssued($loan, [], $actor->id),
            'return_reminder' => new ReturnReminder($loan, $actor->id),
            'overdue' => new LoanOverdue($loan, max(1, $this->stageOf($loan)), sentBy: $actor->id),
        };
        $this->trigger($loan, $notification);
        $this->notifier->toContact($loan, $notification);
    }

    /**
     * Stage of the overdue messages a loan has reached (0: none yet).
     */
    public function stageOf(Loan $loan): int
    {
        $days = $loan->overdueDays();

        return count(array_filter($this->overdueDays(), fn (int $after) => $days >= $after));
    }

    /**
     * Days after which the overdue stages are due, e.g. [1, 3, 7].
     *
     * @return list<int>
     */
    public function overdueDays(): array
    {
        $days = array_map('intval', array_filter(array_map('trim', explode(',', (string) $this->settings->get('loans.overdue_days', '1, 3, 7'))), 'is_numeric'));
        sort($days);

        return array_values(array_unique(array_filter($days, fn (int $day) => $day > 0)));
    }

    /**
     * Delete the push subscriptions of borrower pages whose link was blocked or expired.
     */
    public function cleanUp(LoanPage $page): int
    {
        $count = 0;
        foreach (Loan::whereHas('pushSubscriptions')->get() as $loan) {
            if (! $page->isOpen($loan)) {
                $count += $loan->pushSubscriptions()->delete();
            }
        }

        return $count;
    }

    /**
     * Send a notification unless the log already has it: same type, stage and time it is about
     * (a reminder sent by hand counts too). An extension or a new pickup time starts again.
     */
    private function once(Loan $loan, LoanEventNotification $notification): bool
    {
        if ($loan->notificationLog()->where('type', $notification->type())->where('stage', $notification->stage())->where('due_at', $notification->dueAt())->exists()) {
            return false;
        }
        $this->trigger($loan, $notification);
        if (! $this->sending) {
            return true;
        }
        str_ends_with($notification->type(), '_staff')
            ? $this->notifier->toStaff($loan, $notification)
            : $this->notifier->toContact($loan, $notification);

        return true;
    }

    /**
     * Row "ausgelöst" in the log, written before the delivery.
     */
    private function trigger(Loan $loan, LoanEventNotification $notification): void
    {
        $loan->notificationLog()->create([
            'type' => $notification->type(),
            'stage' => $notification->stage(),
            'due_at' => $notification->dueAt(),
            'channel' => 'trigger',
            'recipient' => match (true) {
                $notification->sentBy !== null => 'von Hand',
                $this->sending => 'automatisch',
                default => 'Demo, nicht verschickt',
            },
            'sent_by' => $notification->sentBy,
            'sent_at' => now(),
        ]);
    }

    /**
     * Loans to contacts with a borrower page.
     *
     * @return Builder<Loan>
     */
    private function external(): Builder
    {
        return Loan::where('kind', LoanKind::External)->whereNotNull('confirmed_at')->whereNull('access_blocked_at');
    }
}
