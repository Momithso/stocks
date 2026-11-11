<?php

namespace Modules\Loans\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Loans\Enums\SignatureRole;

/**
 * A signature on the screen (image) or on paper, with the SHA-256 hash of the content that was
 * signed (see Modules\Loans\Support\Snapshot).
 *
 * @property int $id
 * @property int $loan_id
 * @property int|null $loan_document_id
 * @property SignatureRole $role
 * @property string $name
 * @property string|null $image png as data uri
 * @property Carbon $signed_at
 * @property int|null $user_id
 * @property string|null $device
 * @property string $document_hash
 * @property bool $on_paper
 */
class LoanSignature extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['loan_document_id', 'role', 'name', 'image', 'signed_at', 'user_id', 'device', 'document_hash', 'on_paper'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['role' => SignatureRole::class, 'signed_at' => 'datetime', 'on_paper' => 'boolean'];
    }

    /**
     * @return BelongsTo<LoanDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(LoanDocument::class, 'loan_document_id');
    }
}
