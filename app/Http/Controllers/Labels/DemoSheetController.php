<?php

namespace App\Http\Controllers\Labels;

use App\Http\Controllers\Controller;
use App\Support\Demo\DemoGenerator;
use App\Support\Demo\TestSheet;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test label sheet of the demo data as download, with the address of this instance in the codes
 * (documentation/DEMO.md). Only in an instance with demo data.
 */
class DemoSheetController extends Controller
{
    /**
     * Download the sheet.
     */
    public function show(DemoGenerator $generator, TestSheet $sheet): Response
    {
        abort_unless($generator->hasDemoData(), 404);

        return response($sheet->pdf(), headers: [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="testetiketten.pdf"',
        ]);
    }
}
