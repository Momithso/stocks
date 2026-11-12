<?php

namespace Modules\Example\Http;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ExampleController extends Controller
{
    /**
     * Display the page of the module. Pages of modules are named "<Module>::<Page>". On the kiosk
     * the same action answers; the person that unlocked the kiosk is $request->user() there.
     */
    public function index(): Response
    {
        return Inertia::render('Example::Index', [
            'notes' => DB::table('example_notes')->count(),
        ]);
    }
}
