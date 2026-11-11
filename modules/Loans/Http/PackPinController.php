<?php

namespace Modules\Loans\Http;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Support\Inventory\CodeLookup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Loans\Actions\CheckBox;

/**
 * Pack pin on the public page of a box (/s/{code}), without account. The right pin is kept in the
 * session of the browser for this box, so the page can show the loan and store the check.
 */
class PackPinController extends Controller
{
    public function __construct(private readonly CodeLookup $codes, private readonly CheckBox $checkBox) {}

    /**
     * Check the pin.
     */
    public function unlock(Request $request, string $code): RedirectResponse
    {
        $validated = $request->validate(['pin' => ['required', 'digits:6']]);
        $box = $this->box($code);

        $this->checkBox->unlock($box, $validated['pin']);
        $request->session()->put(self::sessionKey($box), $validated['pin']);

        return back();
    }

    /**
     * Store a check of the box with name and ticks.
     */
    public function store(Request $request, string $code): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'ticks' => ['array', 'max:1000'],
            'ticks.*' => ['string', 'max:30'],
            'total' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);
        $box = $this->box($code);
        $pin = $request->session()->get(self::sessionKey($box))
            ?? throw ValidationException::withMessages(['pin' => 'Gib zuerst die Pack-PIN ein.']);

        $this->checkBox->store($box, $pin, $validated['name'], $validated['ticks'] ?? [], $validated['total']);

        return back();
    }

    /**
     * Session key of the pin of a box.
     */
    public static function sessionKey(Item $box): string
    {
        return "loans.pack_pin.{$box->id}";
    }

    /**
     * Box of a code; a box without public contents has no pack pin field either.
     */
    private function box(string $code): Item
    {
        $box = $this->codes->find($code);
        abort_unless($box instanceof Item && $box->is_container && $box->public_contents && ! $box->isRetired(), 404);

        return $box;
    }
}
