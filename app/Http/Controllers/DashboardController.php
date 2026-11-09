<?php

namespace App\Http\Controllers;

use App\Support\Extensions\DashboardTiles;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the dashboard with the tiles of the core and the active modules.
     */
    public function index(Request $request, DashboardTiles $tiles): Response
    {
        return Inertia::render('Dashboard', [
            'tiles' => $tiles->forUser($request->user()),
        ]);
    }
}
