<?php

namespace Modules\Loans;

use App\Support\Audit\AuditSubjects;
use App\Support\Extensions\DashboardTiles;
use App\Support\Extensions\DemoData;
use App\Support\Extensions\ItemExtensions;
use App\Support\Extensions\Navigation;
use App\Support\Extensions\SurfaceRoutes;
use App\Support\Modules\ModuleRegistry;
use App\Support\Notifications\NotificationEvents;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Loans\Actions\StowItem;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Http\KioskLoanController;
use Modules\Loans\Http\LoanPageAccess;
use Modules\Loans\Models\Contact;
use Modules\Loans\Models\Council;
use Modules\Loans\Models\Event;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanExtensionRequest;
use Modules\Loans\Support\LoanItemInfo;
use Modules\Loans\Support\LoanNotifier;
use Modules\Loans\Support\LoanPage;
use Modules\Loans\Support\LoansDemo;
use Modules\Loans\Support\PackPinSection;
use Modules\Loans\Support\Reminders;

/**
 * Loan module ("Verleih"): contacts, events, loans with availability, calendar, issue and return
 * by scan, contract with signature, borrower page with chat and notifications. See
 * documentation/LOANS.md.
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
    public function boot(Navigation $navigation, DashboardTiles $dashboardTiles, ItemExtensions $items, AuditSubjects $auditSubjects, Settings $settings, DemoData $demoData, SurfaceRoutes $surfaceRoutes, NotificationEvents $events, Router $router): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadViewsFrom(__DIR__.'/resources/views', 'loans');

        $router->aliasMiddleware('loan.page', LoanPageAccess::class);
        Route::middleware(['web', 'auth', 'module:'.self::KEY])->group(__DIR__.'/routes/web.php');
        // pack pin on the public page of a box and the borrower page, without login
        Route::middleware(['web', 'module:'.self::KEY])->group(__DIR__.'/routes/public.php');
        $this->registerLimits();
        $this->registerSchedule();
        $this->registerNotificationEvents($events);
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
            // borrower page: days the link still holds after the loan was closed or cancelled
            'loans.page_days_after_close' => 30,
            // reminders in hours before pickup and return, 0: off
            'loans.remind_pickup_hours' => 24,
            'loans.remind_return_hours' => 24,
            // days after the return time with an overdue message, from the second also to the organization
            'loans.overdue_days' => '1, 3, 7',
            // place of pickup and return in page and messages, empty: address of the organization
            'loans.pickup_place' => null,
            // extra text per message type (Modules\Loans\Notifications), with {number}, {pickup}, {return}, {responsible}
            'loans.mail_texts' => [],
        ]);

        // unread messages of borrowers, for those who answer them
        $navigation->add('loans', 'Buchungen', 'loans.index', 'calendar-check', 'loans.view', self::KEY, order: 10, badge: fn () => auth()->user()?->can('loans.manage') && ($unread = LoanNotifier::unreadCount()) > 0
            ? ['text' => $unread === 1 ? '1 Nachricht' : "{$unread} Nachrichten", 'variant' => 'accent'] : null);
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

        foreach ([Loan::class, Contact::class, Event::class, Council::class, LoanExtensionRequest::class] as $class) {
            $auditSubjects->register($class);
        }
    }

    /**
     * Limits of the borrower page per address and loan (many borrowers can share one address, e.g.
     * the wifi of the university): pages, messages, pin changes, push subscriptions. Over all
     * loans an address gets more, against trying links.
     */
    private function registerLimits(): void
    {
        $key = function (Request $request): string {
            $loan = $request->route('loan');

            return 'ip:'.$request->ip().'|loan:'.(is_object($loan) ? $loan->getKey() : (int) $loan);
        };
        RateLimiter::for('loan-page', fn (Request $request) => [
            Limit::perMinute(60)->by($key($request)),
            Limit::perMinute(600)->by('ip:'.$request->ip()),
        ]);
        RateLimiter::for('loan-page-messages', fn (Request $request) => Limit::perMinute(10)->by($key($request)));
        RateLimiter::for('loan-page-pin', fn (Request $request) => Limit::perHour(5)->by($key($request)));
        RateLimiter::for('loan-page-push', fn (Request $request) => Limit::perHour(10)->by($key($request)));
    }

    /**
     * Time plan (service "scheduler"): reminders every 15 minutes, cleaning up once a day; only
     * while the module is on.
     */
    private function registerSchedule(): void
    {
        $this->commands([Console\RemindCommand::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $active = fn () => app(ModuleRegistry::class)->isActive(self::KEY);
            $schedule->command('loans:remind')->everyFifteenMinutes()->withoutOverlapping()->when($active);
            $schedule->call(fn () => app(Reminders::class)->cleanUp(app(LoanPage::class)))->name('loans:clean-up-push')->daily()->when($active);
        });
    }

    /**
     * Events the module notifies about (documentation/NOTIFICATIONS.md): the borrower side sets
     * them on the borrower page, members in their profile.
     */
    private function registerNotificationEvents(NotificationEvents $events): void
    {
        $contact = fn (string $key, string $label, bool $required = false, ?string $description = null) => $events->add(
            "loans.{$key}", $label, 'Ausleihe', NotificationEvents::CONTACTS, module: self::KEY, requiredMail: $required, description: $description,
        );
        $contact('confirmed', 'Buchung bestätigt', true, 'mit Link und Pack-PIN');
        $contact('pickup_reminder', 'Erinnerung an die Abholung');
        $contact('issued', 'Ausgabe erfolgt', true, 'mit Leihvertrag und Materialliste');
        $contact('return_reminder', 'Erinnerung an die Rückgabe');
        $contact('overdue', 'Überfällig', true);
        $contact('chat', 'Neue Chat-Nachricht');
        $contact('extension_decided', 'Verlängerung entschieden');
        $contact('returned', 'Rückgabe gebucht', true, 'mit Rückgabebeleg');
        $contact('defect', 'Mangel gemeldet');
        $contact('cancelled', 'Storniert');

        $member = fn (string $key, string $label, ?string $description = null) => $events->add(
            "loans.{$key}", $label, 'Verleih', NotificationEvents::MEMBERS, 'loans.manage', self::KEY, description: $description,
        );
        $member('chat_staff', 'Neue Chat-Nachricht', 'bei Buchungen, die du angelegt, ausgegeben oder im Chat beantwortet hast');
        $member('extension_requested', 'Verlängerung angefragt', 'bei denselben Buchungen');
        $member('overdue_staff', 'Überfällig', 'ab der zweiten Stufe, bei denselben Buchungen');
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
