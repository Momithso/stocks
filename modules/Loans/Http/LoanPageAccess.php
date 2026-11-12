<?php

namespace Modules\Loans\Http;

use Closure;
use Illuminate\Http\Request;
use Modules\Loans\Models\Loan;
use Modules\Loans\Support\LoanPage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Access to the borrower page (middleware "loan.page"): a loan to contacts, confirmed, not blocked,
 * not expired, and the key of the link fits. Every failure answers the same 404, so nobody learns
 * whether a loan exists. Search engines and referrers get nothing.
 */
class LoanPageAccess
{
    public function __construct(private readonly LoanPage $page) {}

    public function handle(Request $request, Closure $next): Response
    {
        $parameter = $request->route('loan');
        $loan = $parameter instanceof Loan ? $parameter : Loan::find((int) $parameter);
        $token = (string) $request->route('token');
        abort_unless($loan !== null && $token !== '' && LoanPage::matches($loan, $token) && $this->page->isOpen($loan), 404);

        // the controllers get the loan as parameter
        $request->route()->setParameter('loan', $loan);
        $request->route()->forgetParameter('token');

        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
