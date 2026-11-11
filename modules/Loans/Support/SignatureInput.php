<?php

namespace Modules\Loans\Support;

use App\Models\User;
use Modules\Loans\Enums\SignatureRole;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanDocument;
use Modules\Loans\Models\LoanSignature;

/**
 * Signatures sent by the signature field: a png as data uri per role, or "on_paper" when both
 * signed a printout. Images are checked to be a real png of limited size.
 */
class SignatureInput
{
    /**
     * Largest image in bytes (a signature is a few kilobytes).
     */
    private const MAX_BYTES = 400_000;

    /**
     * Validation rules for the given roles.
     *
     * @param  list<SignatureRole>  $roles
     * @return array<string, mixed>
     */
    public static function rules(array $roles): array
    {
        $rules = ['on_paper' => ['boolean']];
        foreach ($roles as $role) {
            $rules["signatures.{$role->value}"] = ['required_unless:on_paper,true', 'nullable', 'string', 'starts_with:data:image/png;base64,', 'max:'.(int) (self::MAX_BYTES * 4 / 3 + 30)];
        }

        return $rules;
    }

    /**
     * Check that a data uri holds a png.
     */
    public static function isPng(?string $dataUri): bool
    {
        $content = base64_decode(substr((string) $dataUri, strlen('data:image/png;base64,')), true);

        return $content !== false && str_starts_with($content, "\x89PNG\r\n\x1a\n");
    }

    /**
     * Store the signatures of a document.
     *
     * @param  array<string, array{name: string, image: string|null, user: User|null}>  $signers  by role value
     */
    public static function store(Loan $loan, LoanDocument $document, array $signers, bool $onPaper, ?string $device): void
    {
        foreach ($signers as $role => $signer) {
            $loan->signatures()->create([
                'loan_document_id' => $document->id,
                'role' => SignatureRole::from($role),
                'name' => $signer['name'],
                'image' => $onPaper ? null : $signer['image'],
                'signed_at' => now(),
                'user_id' => $signer['user']?->id,
                'device' => mb_substr((string) $device, 0, 255),
                'document_hash' => $document->hash,
                'on_paper' => $onPaper,
            ]);
        }
    }

    /**
     * Data of the signatures of a document for pdf and page.
     *
     * @return list<array{role: string, name: string, image: string|null, signed_at: string, on_paper: bool}>
     */
    public static function summaries(LoanDocument $document): array
    {
        return $document->signatures->map(fn (LoanSignature $signature) => [
            'role' => $signature->role->label(),
            'name' => $signature->name,
            'image' => $signature->image,
            'signed_at' => $signature->signed_at->toIso8601String(),
            'on_paper' => $signature->on_paper,
        ])->all();
    }
}
