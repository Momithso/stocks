<?php

namespace Modules\Loans\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Loans\Enums\DocumentType;
use Modules\Loans\Support\Snapshot;

/**
 * A document of a loan with the frozen content that was signed (snapshot) and its SHA-256 hash.
 * The pdf is made from the snapshot when it is downloaded, so it always shows exactly what was
 * signed. A paper scan has a file on S3 instead.
 *
 * @property int $id
 * @property int $loan_id
 * @property DocumentType $type
 * @property array<string, mixed>|null $snapshot
 * @property string|null $hash
 * @property string|null $path
 * @property int|null $created_by
 */
class LoanDocument extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['type', 'snapshot', 'hash', 'path', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => DocumentType::class, 'snapshot' => 'array'];
    }

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * @return HasMany<LoanSignature, $this>
     */
    public function signatures(): HasMany
    {
        return $this->hasMany(LoanSignature::class)->orderBy('id');
    }

    /**
     * Check if the stored content still has the hash it was signed with.
     */
    public function isUnchanged(): bool
    {
        return $this->snapshot !== null && hash_equals((string) $this->hash, Snapshot::hash($this->snapshot));
    }
}
