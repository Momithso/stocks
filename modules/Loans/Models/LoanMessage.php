<?php

namespace Modules\Loans\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Message in the chat of a loan between the borrower page and the organization. Shown as text,
 * never as html. Not audited: the chat is its own log.
 *
 * @property int $id
 * @property int $loan_id
 * @property string $sender "contact", "user" or "system"
 * @property int|null $user_id
 * @property string $author_name
 * @property string $body
 * @property Carbon|null $read_by_staff_at
 * @property Carbon|null $read_by_contact_at
 * @property Carbon $created_at
 */
class LoanMessage extends Model
{
    public const CONTACT = 'contact';

    public const USER = 'user';

    public const SYSTEM = 'system';

    /**
     * Longest message in characters.
     */
    public const MAX_LENGTH = 2000;

    /**
     * @var list<string>
     */
    protected $fillable = ['sender', 'user_id', 'author_name', 'body'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['read_by_staff_at' => 'datetime', 'read_by_contact_at' => 'datetime'];
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Data for the chat. "mine" is from the point of view of the side that reads ("contact" or
     * "staff"); for "staff" only the messages of the member $userId.
     *
     * @return array{id: int, sender: string, author: string, body: string, created_at: string, mine: bool}
     */
    public function summary(string $side, ?int $userId = null): array
    {
        return [
            'id' => $this->id,
            'sender' => $this->sender,
            'author' => $this->author_name,
            'body' => $this->body,
            'created_at' => $this->created_at->toIso8601String(),
            // for members only their own messages, not those of colleagues
            'mine' => $side === 'contact' ? $this->sender === self::CONTACT : $this->sender === self::USER && $userId !== null && (int) $this->user_id === $userId,
        ];
    }
}
