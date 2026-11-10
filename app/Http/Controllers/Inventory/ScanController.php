<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\ItemCondition;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page "Scannen": camera (or hand scanner) with actions right at the scanned item, box or location.
 * The scanned code is found by ItemController::lookup, the actions use the routes of the item page.
 */
class ScanController extends Controller
{
    /**
     * Display the scan page.
     */
    public function show(): Response
    {
        return Inertia::render('Inventory/Scan', [
            'conditions' => ItemCondition::options(),
        ]);
    }
}
