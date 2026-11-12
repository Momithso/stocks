<?php

namespace Modules\Loans\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Log of a notification of a loan: one row per receiver and channel (a mail to two persons is two
 * rows, mail and push to one person as well). Shows who got what when, and keeps the reminders
 * from going out twice (loans:remind).
 *
 * @property int $id
 * @property int $loan_id
 * @property string $type key of the event without "loans.", e.g. "overdue"
 * @property int|null $stage
 * @property Carbon|null $due_at pickup or return time a reminder was about
 * @property string $channel "mail" or "push"
 * @property string $recipient
 * @property int|null $sent_by null: sent automatically
 * @property Carbon $sent_at
 */
class LoanNotification extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['type', 'stage', 'due_at', 'channel', 'recipient', 'sent_by', 'sent_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'due_at' => 'datetime', 'stage' => 'integer'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
