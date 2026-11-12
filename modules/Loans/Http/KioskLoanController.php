<?php

namespace Modules\Loans\Http;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Support\Inventory\CodeLookup;
use App\Support\Surface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Loans\Actions\IssueLoan;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Enums\ReturnState;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanItem;

/**
 * Start of the loan tasks on the kiosk: what is picked up today, what is out, and a scan that
 * opens the right step (issue, return or inspection) for a loan number, a box or an item.
 * The steps themselves are the pages of the pc (routes/kiosk.php).
 */
class KioskLoanController extends Controller
{
    /**
     * Most loans per list.
     */
    private const LIMIT = 50;

    public function __construct(private readonly CodeLookup $codes) {}

    /**
     * Display the loans to issue (pickup today or earlier), the loans that are out and those with
     * open defects; "show" (pickups, issued) limits the page, as the tiles of the kiosk do.
     */
    public function index(Request $request): Response
    {
        $list = fn (Builder $query) => $query->with(['borrower', 'responsible', 'responsibleUser'])->limit(self::LIMIT)->get()
            ->map(fn (Loan $loan) => $loan->summary());

        return Inertia::render('Loans::Kiosk', [
            // to pick up today (or missed before, while the time runs), and the rest of a partial
            // issue that may still leave; after the return time neither holds anything (Loan::isNotPickedUp())
            'pickups' => $list(self::pickups()->orderBy('pickup_at'))
                ->map(fn (array $loan) => [
                    ...$loan,
                    'rest' => $loan['status'] === LoanStatus::Issued->value,
                    'missed' => $loan['status'] === LoanStatus::Confirmed->value && Carbon::parse($loan['pickup_at'])->lt(today()),
                ]),
            'issued' => $list(Loan::where('status', LoanStatus::Issued)->orderBy('return_at')),
            'unchecked' => Loan::where('status', LoanStatus::ReturnedUnchecked)->count(),
            // open defects: closed with defects, or still out after a part came back with defects
            'defects' => $list(Loan::where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('status', LoanStatus::ClosedWithDefects)->whereNull('defects_closed_at'))
                ->orWhere(fn (Builder $query) => $query->where('status', LoanStatus::Issued)
                    ->whereHas('loanItems', fn (Builder $query) => $query->whereNotNull('return_state')->where('return_state', '!=', ReturnState::Ok))))
                ->orderByDesc('returned_at')),
            'show' => in_array($request->query('show'), ['pickups', 'issued'], true) ? $request->query('show') : null,
        ]);
    }

    /**
     * Loans to issue on the kiosk: confirmed with the pickup today or before, and rests of a
     * partial issue, both only while their return time has not passed.
     *
     * @return Builder<Loan>
     */
    public static function pickups(): Builder
    {
        return Loan::where('return_at', '>', now())->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->where('status', LoanStatus::Confirmed)->where('pickup_at', '<=', today()->endOfDay()))
            ->orWhere(fn (Builder $query) => $query->where('status', LoanStatus::Issued)->whereHas('positions', fn (Builder $query) => $query->whereNull('issued_at'))));
    }

    /**
     * Open the step for a scanned or typed code: the loan number, or an item or box of a loan.
     */
    public function find(Request $request): RedirectResponse
    {
        $code = trim((string) $request->query('code'));
        [$loan, $toLeave] = $this->loanOf($code);

        return match (true) {
            $loan === null => back()->with('error', "Zu {$code} gibt es keine Buchung zum Ausgeben oder Zurücknehmen."),
            // to leave: the issue; an issued loan with nothing out any more only has its rest left
            $loan->status === LoanStatus::Confirmed || ($toLeave && IssueLoan::hasRest($loan))
                || ($loan->status === LoanStatus::Issued && ! $loan->loanItems()->whereNotNull('issued_at')->whereNull('returned_at')->exists()) => redirect(Surface::route('loans.issue', $loan)),
            $loan->status === LoanStatus::Issued => redirect(Surface::route('loans.return.show', [$loan, 'return'])),
            $loan->status === LoanStatus::ReturnedUnchecked => redirect(Surface::route('loans.return.show', [$loan, 'inspect'])),
            default => back()->with('warning', "{$loan->number} ist {$loan->status->label()}, hier gibt es nichts zu tun."),
        };
    }

    /**
     * The loan of a code: by its number, else the loan that has the item out, else the next loan
     * the item is still to leave with (confirmed, or the rest of a partial issue). The second value
     * says if the item is still to leave.
     *
     * @return array{0: Loan|null, 1: bool}
     */
    private function loanOf(string $code): array
    {
        if ($code === '') {
            return [null, false];
        }

        $loan = Loan::whereRaw('upper(number) = ?', [mb_strtoupper($code)])->first();
        $item = $loan ? null : $this->codes->find($code);
        if (! $item instanceof Item) {
            return [$loan, false];
        }

        $out = LoanItem::whereNotNull('issued_at')->whereNull('returned_at')
            ->where(fn (Builder $query) => $query->where('item_id', $item->id)->orWhere('issued_item_id', $item->id))
            ->whereHas('loan', fn (Builder $query) => $query->where('status', LoanStatus::Issued))
            ->first();

        if ($out) {
            return [$out->loan, false];
        }

        return [Loan::whereIn('status', [LoanStatus::Confirmed, LoanStatus::Issued])
            ->whereHas('loanItems', fn (Builder $query) => $query->where('item_id', $item->id)->whereNull('issued_at'))
            ->orderBy('pickup_at')
            ->first(), true];
    }
}
