<?php

namespace Modules\Loans\Support;

use App\Support\QrCode;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Modules\Loans\Enums\LoanKind;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Loan;

/**
 * The borrower page of a loan ("Verleihseite"): a page without login under a long link, from the
 * confirmation on, for loans to contacts (not for own use). See documentation/LOANS.md.
 *
 * The key of the link is not stored: it is computed from APP_KEY, the loan and a version
 * (HMAC-SHA-256, 32 bytes, base64url), so Stocks can write the link into every mail and code
 * again. A new link raises the version; the old one is invalid at once. The link holds during the
 * whole loan and expires "loans.page_days_after_close" days after the loan was closed or cancelled.
 */
class LoanPage
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Key of the link of a loan in its current version.
     */
    public static function token(Loan $loan): string
    {
        $raw = hash_hmac('sha256', "loan-page|{$loan->id}|{$loan->access_version}", (string) config('app.key'), true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Check a key against the current one (timing safe).
     */
    public static function matches(Loan $loan, string $token): bool
    {
        return hash_equals(self::token($loan), $token);
    }

    /**
     * Full address of the page, optionally of a tab ("chat", "material").
     */
    public static function url(Loan $loan, ?string $tab = null): string
    {
        return route('loans.page.show', ['loan' => $loan->id, 'token' => self::token($loan), ...($tab ? ['tab' => $tab] : [])]);
    }

    /**
     * Path of the page (scope of its web app and service worker), e.g. "/v/12/abc…".
     */
    public static function path(Loan $loan): string
    {
        return '/v/'.$loan->id.'/'.self::token($loan);
    }

    /**
     * Check if the loan has a borrower page at all: a loan to contacts that was confirmed.
     */
    public static function exists(Loan $loan): bool
    {
        return $loan->kind === LoanKind::External && $loan->status !== LoanStatus::Draft && $loan->confirmed_at !== null;
    }

    /**
     * Time the loan was closed: done, cancelled, or the defects closed. Null while it runs (also
     * while it is overdue, unchecked or has open defects).
     */
    public static function closedAt(Loan $loan): ?Carbon
    {
        return match ($loan->status) {
            LoanStatus::Closed => $loan->inspected_at ?? $loan->returned_at,
            LoanStatus::ClosedWithDefects => $loan->defects_closed_at,
            LoanStatus::Cancelled => $loan->cancelled_at,
            default => null,
        };
    }

    /**
     * End of the link, null while the loan runs.
     */
    public function expiresAt(Loan $loan): ?Carbon
    {
        $closed = self::closedAt($loan);

        return $closed?->copy()->addDays((int) $this->settings->get('loans.page_days_after_close', 30));
    }

    /**
     * Check if the link opens the page now: it exists, is not blocked and not expired.
     */
    public function isOpen(Loan $loan): bool
    {
        $expires = $this->expiresAt($loan);

        return self::exists($loan) && $loan->access_blocked_at === null && ($expires === null || $expires->isFuture());
    }

    /**
     * Block the link, or open it again.
     */
    public function block(Loan $loan, bool $blocked): void
    {
        $loan->forceFill(['access_blocked_at' => $blocked ? now() : null])->save();
        if ($blocked) {
            $loan->pushSubscriptions()->delete();
        }
    }

    /**
     * Issue a new link: the old one stops working, its devices get no more push.
     */
    public function reissue(Loan $loan): void
    {
        $loan->forceFill(['access_version' => $loan->access_version + 1])->save();
        $loan->pushSubscriptions()->delete();
    }

    /**
     * QR code of the link as svg data uri, for the pdfs (dompdf reads svg); null without page.
     */
    public static function qrDataUri(Loan $loan): ?string
    {
        if (! self::exists($loan)) {
            return null;
        }

        return 'data:image/svg+xml;base64,'.base64_encode(QrCode::svg(self::url($loan), 200));
    }
}
