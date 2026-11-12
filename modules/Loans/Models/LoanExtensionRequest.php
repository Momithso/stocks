<?php

namespace Modules\Loans\Models;

use App\Models\User;
use App\Support\Audit\AuditSubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Request of the borrower page for a later return time. A member with "loans.manage" accepts it
 * (Modules\Loans\Actions\ExtendLoan) or declines it with an answer. One open request per loan.
 *
 * @property int $id
 * @property int $loan_id
 * @property Carbon $requested_return_at
 * @property string|null $note
 * @property string $status "open", "accepted" or "declined"
 * @property string|null $answer
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 */
class LoanExtensionRequest extends Model implements Auditable, AuditSubject
{
    use \OwenIt\Auditing\Auditable;

    public const OPEN = 'open';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    /**
     * Closed without a decision: the material came back or the loan was cancelled first.
     */
    public const DONE = 'done';

    /**
     * Close the open request of a loan that no longer runs, with a note in the chat (no message
     * to the borrower; the return or cancellation tells them anyway).
     */
    public static function closeOpen(Loan $loan, string $reason): void
    {
        foreach ($loan->extensionRequests()->where('status', self::OPEN)->get() as $request) {
            $request->forceFill(['status' => self::DONE, 'answer' => $reason, 'decided_at' => now()])->save();
            $loan->messages()->create([
                'sender' => LoanMessage::SYSTEM,
                'author_name' => 'Stocks',
                'body' => "Verlängerungsanfrage erledigt: {$reason}",
            ]);
        }
    }

    /**
     * @var list<string>
     */
    protected $fillable = ['requested_return_at', 'note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['requested_return_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Data for the pages.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'requested_return_at' => $this->requested_return_at->toIso8601String(),
            'note' => $this->note,
            'status' => $this->status,
            'answer' => $this->answer,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public static function auditTypeLabel(): string
    {
        return 'Verlängerungsanfrage';
    }

    public static function auditName(array $attributes): string
    {
        return isset($attributes['requested_return_at']) ? 'bis '.Carbon::parse($attributes['requested_return_at'])->format('d.m.Y H:i') : '';
    }

    public static function auditFieldLabels(): array
    {
        return ['requested_return_at' => 'Gewünschte Rückgabe', 'note' => 'Notiz', 'status' => 'Status', 'answer' => 'Antwort', 'decided_by' => 'Entschieden von', 'decided_at' => 'Entschieden am', 'loan_id' => 'Buchung'];
    }
}
