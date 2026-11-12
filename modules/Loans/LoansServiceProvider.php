<?php

namespace Modules\Loans;

use App\Support\Audit\AuditSubjects;
use App\Support\Extensions\DashboardTiles;
use App\Support\Extensions\DemoData;
use App\Support\Extensions\ItemExtensions;
use App\Support\Extensions\Navigation;
use App\Support\Extensions\SurfaceRoutes;
use App\Support\Settings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Loans\Actions\StowItem;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Http\KioskLoanController;
use Modules\Loans\Models\Contact;
use Modules\Loans\Models\Council;
use Modules\Loans\Models\Event;
use Modules\Loans\Models\Loan;
use Modules\Loans\Support\LoanItemInfo;
use Modules\Loans\Support\LoansDemo;
use Modules\Loans\Support\PackPinSection;

/**
 * Loan module ("Verleih"): contacts, events, loans with availability, calendar, issue and return
 * by scan, contract with signature. See documentation/LOANS.md.
 */
class LoansServiceProvider extends ServiceProvider
{
    /**
     * Key of the module, as in module.json.
     *
     * @var string
     */
    public const KEY = 'loans';

    /**
     * Bootstrap the module.
     */
    public function boot(Navigation $navigation, DashboardTiles $dashboardTiles, ItemExtensions $items, AuditSubjects $auditSubjects, Settings $settings, DemoData $demoData, SurfaceRoutes $surfaceRoutes): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadViewsFrom(__DIR__.'/resources/views', 'loans');

        Route::middleware(['web', 'auth', 'module:'.self::KEY])->group(__DIR__.'/routes/web.php');
        // pack pin on the public page of a box, without login
        Route::middleware(['web', 'module:'.self::KEY])->group(__DIR__.'/routes/public.php');
        // issue, return and inspection on the kiosk, while the kiosk module is on
        $surfaceRoutes->add('kiosk', __DIR__.'/routes/kiosk.php', self::KEY);

        $settings->addDefaults([
            // contract text, empty for the text that comes with the module (resources/contract.txt)
            'loans.contract_template' => null,
            'loans.lender' => 'Studierendenschaft der FH Aachen, vertreten durch den Fachschaftsrat …',
            // place of the signature in the contract, empty for the city of the organization's address
            'loans.place' => null,
            'loans.buffer_hours' => 0,
            'loans.number_prefix' => 'B',
        ]);

        $navigation->add('loans', 'Buchungen', 'loans.index', 'calendar-check', 'loans.view', self::KEY, order: 10);
        $navigation->add('loans', 'Kalender', 'loans.calendar', 'calendar-days', 'loans.view', self::KEY, order: 20);
        $navigation->add('loans', 'Rückgaben', 'loans.returns', 'rotate-left', 'loans.issue', self::KEY, order: 30);
        $navigation->add('loans', 'Veranstaltungen', 'loans.events.index', 'champagne-glasses', 'loans.view', self::KEY, order: 40);
        $navigation->add('loans', 'Kontakte', 'loans.contacts.index', 'address-book', 'loans.manage', self::KEY, order: 50);
        $navigation->add('admin', 'Verleih', 'loans.settings', 'file-signature', 'loans.settings', self::KEY, order: 48, group: 'settings');

        // the kiosk: one tile per task, with how much there is to do
        $navigation->addKioskTile('Material ausgeben', 'kiosk.loans.index', 'arrow-right-from-bracket', 'loans.issue', self::KEY, order: 10, parameters: ['show' => 'pickups'],
            description: 'Eine Buchung wird abgeholt: Material abhaken, unterschreiben, mitgeben.', section: 'Verleih',
            // pickups of today and rests that may still leave (missed pickups are in the list, not counted)
            badge: fn () => $this->kioskBadge(KioskLoanController::pickups()->where(fn ($query) => $query->where('status', LoanStatus::Issued)->orWhere('pickup_at', '>=', today()))->count(), 'heute', 'accent'));
        $navigation->addKioskTile('Material zurücknehmen', 'kiosk.loans.index', 'rotate-left', 'loans.issue', self::KEY, order: 20, parameters: ['show' => 'issued'],
            description: 'Etwas kommt zurück: zählen, Mängel vermerken, unterschreiben.', section: 'Verleih',
            badge: fn () => $this->kioskBadge(Loan::overdue()->count(), 'überfällig', 'danger') ?? $this->kioskBadge(Loan::where('status', LoanStatus::Issued)->count(), 'ausgegeben', 'neutral'));
        $navigation->addKioskTile('Einräumen und prüfen', 'kiosk.loans.returns', 'warehouse', 'loans.issue', self::KEY, order: 30,
            description: 'Zurückgekommenes an seinen Platz bringen, ungeprüfte Rückgaben prüfen.', section: 'Verleih',
            badge: fn () => $this->kioskBadge(StowItem::pending()->count() + Loan::where('status', LoanStatus::ReturnedUnchecked)->count(), 'offen', 'warning'));

        $dashboardTiles->add('Loans::DashboardTile', 'loans.view', self::KEY, order: 20);
        $items->addTab('Verleih', 'Loans::ItemTab', 'loans.view', self::KEY);
        $items->addInfo(LoanItemInfo::class, self::KEY);
        $items->addPublicBoxSection('Loans::PublicPackPin', PackPinSection::class, self::KEY);
        $demoData->add(LoansDemo::class, self::KEY, order: 50);

        foreach ([Loan::class, Contact::class, Event::class, Council::class] as $class) {
            $auditSubjects->register($class);
        }
    }

    /**
     * Badge of a kiosk tile: "3 heute", nothing when there is nothing to do.
     *
     * @return array{text: string, variant: string}|null
     */
    private function kioskBadge(int $count, string $text, string $variant): ?array
    {
        return $count > 0 ? ['text' => "{$count} {$text}", 'variant' => $variant] : null;
    }
}
