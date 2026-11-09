<?php

namespace Modules\Example\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ExampleController extends Controller
{
    /**
     * Display the page of the module. Pages of modules are named "<Module>::<Page>".
     */
    public function index(): Response
    {
        return Inertia::render('Example::Index', [
            'notes' => DB::table('example_notes')->count(),
        ]);
    }

    /**
     * Display the kiosk page of the module. The person that unlocked the kiosk is a request
     * attribute, see App\Http\Middleware\EnsureKioskUnlocked.
     */
    public function kiosk(Request $request): Response
    {
        $person = $request->attributes->get('kioskUser');
        abort_unless($person->can('example.view'), 403);

        return Inertia::render('Example::Kiosk', [
            'person' => $person->only(['firstname', 'lastname']),
        ]);
    }
}
