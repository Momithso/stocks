# Benachrichtigungen

Seit Phase 5 benachrichtigt Stocks per **E-Mail** und **Push** (Web Push über die Web-App). Der Helfer liegt im Kern, damit spätere Phasen (Anfrage-Website, SMS) und andere Module ihn weiter nutzen. Die Nachrichten des Verleihs stehen in [LOANS.md](LOANS.md#nachrichten-an-entleiher-und-fsr).

## Inhalt

- [Überblick](#überblick)
- [Neue Benachrichtigung in drei Schritten](#neue-benachrichtigung-in-drei-schritten)
- [Ereignisse anmelden](#ereignisse-anmelden)
- [Einstellungen](#einstellungen)
- [Push einrichten](#push-einrichten)
- [Push je Gerät](#push-je-gerät)
- [Warteschlange und Zeitplan](#warteschlange-und-zeitplan)
- [Testen und Fehlersuche](#testen-und-fehlersuche)
- [Code](#code)

## Überblick

| Baustein | Aufgabe |
|---|---|
| `App\Support\Notifications\NotificationEvents` | Erweiterungspunkt: Kern und Module melden ihre Ereignisse an (Schlüssel, Bezeichnung, Gruppe, Empfänger Mitglieder oder Kontakte, nötiges Recht, Vorgabe-Kanäle, Pflicht-Mail). Daraus entstehen die Tabellen im Profil und auf der Verleihseite. |
| `App\Notifications\StocksNotification` | Basisklasse (abstrakt, `ShouldQueue`). Unterklassen liefern `event()` und `message()`; `via()` wählt die Kanäle aus den Einstellungen des Empfängers und seinen Geräten mit Push. `toMail()` und `toWebPush()` entstehen beide aus derselben `NotificationMessage`. |
| `App\Support\Notifications\NotificationMessage` | Inhalt: Titel, Absätze, Adresse, Knopftext, ein kurzer Satz für Push (höchstens 120 Zeichen), Anhänge (nur Mail, erst beim Versand erzeugt). |
| `App\Support\Notifications\WebPush` | Ist Push an (Schlüssel da)? Welche Push-Dienste sind erlaubt? Abo eines Geräts speichern, Gerätename aus dem Browser. |
| `App\Models\PushSubscription` | Abo eines Geräts (Tabelle `push_subscriptions`): für ein Konto oder für die Verleihseite einer Buchung; mit `device` und `last_used_at`. |
| `public/sw.js` | Service Worker, nur für Push: zeigt jede Nachricht sofort, ein Tipp öffnet ihre Adresse oder holt ein offenes Fenster nach vorn. Kein Cache, kein Offline-Betrieb. |
| `resources/js/composables/usePush.ts` | Push auf diesem Gerät ein- und ausschalten (Erlaubnis nur nach Tipp), Zustand (an, aus, blockiert, nicht möglich, „nur vom Home-Bildschirm“). |
| `components/notifications/Settings.vue`, `PushGuide.vue` | Tabelle der Ereignisse mit E-Mail und Push, Knopf und Anleitung je Gerät; im Profil und auf der Verleihseite. |

Die Einladung (`InvitationNotification`) und „Passwort vergessen“ gehen immer per Mail und stehen in keiner Tabelle.

## Neue Benachrichtigung in drei Schritten

1. **Ereignis anmelden** im `boot()` des Providers (siehe unten), z. B. `inventory.retired`.
2. **Klasse schreiben**, abgeleitet von `StocksNotification`:

   ```php
   class ItemRetired extends StocksNotification
   {
       public function __construct(public readonly Item $item) {}

       public function event(): string
       {
           return 'inventory.retired';
       }

       public function message(object $notifiable): NotificationMessage
       {
           return new NotificationMessage(
               title: "{$this->item->number} ausgemustert",
               lines: ["{$this->item->name} wurde ausgemustert."],
               url: route('inventory.items.show', $this->item),
               action: 'Gegenstand öffnen',
           );
       }
   }
   ```

3. **Verschicken** mit `$user->notify(new ItemRetired($item))` oder `Notification::send($users, …)`. Kanäle, Einstellungen und Push kommen von selbst; wer das Recht des Ereignisses nicht hat oder deaktiviert ist, bekommt nichts.

Wer ein Protokoll braucht, überschreibt `sent($notifiable, $channel, $response)`; der Kern ruft es nach jedem Versand auf (Listener auf `NotificationSent`). Der Verleih schreibt so `loan_notifications`. Was nichts verschicken darf (der Demo-Generator), läuft in `NotificationEvents::quietly(fn () => …)`.

## Ereignisse anmelden

```php
$events->add(
    'loans.chat_staff',            // Schlüssel
    'Neue Chat-Nachricht',         // Bezeichnung in der Tabelle
    'Verleih',                     // Gruppe
    NotificationEvents::MEMBERS,   // MEMBERS (Profil) oder CONTACTS (Verleihseite)
    'loans.manage',                // nur Konten mit diesem Recht sehen und bekommen es
    'loans',                       // Modul; aus, wenn das Modul aus ist
    defaults: ['mail' => true, 'push' => true],
    requiredMail: false,           // true: kommt immer per Mail, nicht abschaltbar
    description: 'bei Buchungen, die du angelegt hast …',
);
```

## Einstellungen

- **Mitglieder:** Profil › Benachrichtigungen, je Ereignis E-Mail und Push. Es erscheinen nur Ereignisse, für die das Konto das Recht hat. Gespeichert wird nur, was von der Vorgabe abweicht (`users.notification_preferences`). Darunter die Geräte mit Push (Gerät, eingetragen, zuletzt genutzt, „Entfernen“), „Testmail senden“ und „Testbenachrichtigung senden“. Am Kiosk gibt es die Karte nicht.
- **Entleiher:** Karte „Benachrichtigungen“ auf der Verleihseite, gespeichert an der Buchung (`loans.notification_preferences`), gilt für alle mit dem Link. Pflicht-Mails haben einen festen Haken „kommt immer“.
- Die Spalte Push ist ausgegraut, solange kein Gerät Push eingeschaltet hat; ohne Schlüssel auf dem Server fehlt sie ganz.

## Push einrichten

1. Schlüssel einmal erzeugen: `php artisan webpush:vapid --show` und die beiden Zeilen in die `.env` eintragen (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`; optional `VAPID_SUBJECT` mit `mailto:` oder der Adresse der Instanz). In Docker: in die `.env` neben `docker-compose.yaml`, dann `docker compose up -d`. **Die Schlüssel nicht wechseln:** neue Schlüssel beenden jedes Abo.
2. Stocks muss über **HTTPS** laufen (Service Worker gibt es nur dort; `localhost` ausgenommen).
3. Der Server muss die Push-Dienste erreichen: `*.push.apple.com`, `fcm.googleapis.com`, `updates.push.services.mozilla.com`, `*.notify.windows.com`. Nur Abos dieser Dienste nimmt Stocks an (sonst könnte jemand Stocks Anfragen an beliebige Adressen schicken lassen); höchstens 10 Geräte je Konto und je Buchung.
4. Verwaltung › Einstellungen › Benachrichtigungen zeigt, ob Push eingerichtet ist und wie viele Geräte es nutzen.

Ohne Schlüssel ist Push aus: keine Push-Spalte, keine Knöpfe, nur Mail.

Push-Nachrichten sind verschlüsselt, laufen aber über Apple, Google, Mozilla oder Microsoft. Sie tragen deshalb nur Titel („B-2026-0042 · Neue Nachricht“), einen Satz und die Adresse, nie Anschrift oder Pack-PIN. Abos, die der Dienst als ungültig meldet (404, 410), löscht das Paket.

## Push je Gerät

| Gerät | So geht es |
|---|---|
| iPhone, iPad (ab iOS 16.4) | In Safari Teilen → „Zum Home-Bildschirm“, die App vom Home-Bildschirm öffnen, „Push auf diesem Gerät einschalten“ tippen, erlauben. Im normalen Browser-Tab geht Push am iPhone nicht. |
| Android (Chrome, Firefox, Samsung Internet) | Knopf tippen, erlauben. Zum Startbildschirm hinzufügen geht, ist aber nicht nötig. |
| PC, Mac (Chrome, Edge, Firefox, Safari ab macOS 13) | Knopf klicken, erlauben; der Browser muss laufen. |
| Abgelehnt oder blockiert | In den Einstellungen des Browsers (Schloss neben der Adresse) bzw. am iPhone unter Einstellungen › Mitteilungen wieder erlauben. |

Mitglieder registrieren den Worker mit dem Scope `/`, die Verleihseite mit ihrem Link als Scope und eigenem Manifest; so ist sie auf dem Home-Bildschirm eine eigene App.

**Ergebnis des Tests auf echten Geräten:** steht in [DECISIONS.md](DECISIONS.md), sobald Maurice iPhone, Android und PC auf stocks-dev geprüft hat. Geht es auf einem Gerät nicht, bleibt dort die Mail.

## Warteschlange und Zeitplan

- Benachrichtigungen laufen über die Warteschlange (`QUEUE_CONNECTION=redis`, Dienst `queue` mit `php artisan queue:work --tries=3 --max-time=3600`). Ohne laufenden Dienst bleiben sie liegen.
- Der Dienst `scheduler` (`php artisan schedule:work`) startet die Erinnerungen des Verleihs (`loans:remind`, alle 15 Minuten) und das tägliche Aufräumen der Push-Abos.
- Fehlgeschlagene Aufträge landen in `failed_jobs`; Verwaltung › Einstellungen › Benachrichtigungen zeigt ihre Zahl mit „Erneut versuchen“.
- Tests laufen mit `sync` und `Notification::fake()`.

## Testen und Fehlersuche

- `php artisan stocks:notify-test <email> --channel=mail|push` schickt eine Testbenachrichtigung sofort (ohne Warteschlange) und zeigt Fehler direkt. Dasselbe als Knopf im Profil.
- Kommt keine Mail: Verwaltung › Einstellungen › Benachrichtigungen (Verbindung, Test-Mail, fehlgeschlagene Aufträge), läuft der Dienst `queue`?
- Kommt kein Push: Schlüssel gesetzt (Seite zeigt „Push ist eingerichtet“)? HTTPS? Gerät im Profil eingetragen? Am iPhone von der App auf dem Home-Bildschirm eingeschaltet? Erreicht der Server die Push-Dienste? `last_used_at` des Geräts zeigt die letzte Zustellung.
- Die Verleihseite verliert ihre Abos, wenn ihr Link gesperrt, neu ausgestellt oder abgelaufen ist.

## Code

| Was | Wo |
|---|---|
| Helfer | `app/Support/Notifications`, `app/Notifications/StocksNotification.php`, `TestNotification.php` |
| Push | `config/webpush.php`, `app/Models/PushSubscription.php`, `public/sw.js`, `resources/js/composables/usePush.ts` |
| Profil | `ProfileController` (`notifications`, `subscribe`, `unsubscribe`, `test`), `pages/Profile/Edit.vue` |
| Befehl | `routes/console.php` (`stocks:notify-test`) |
| Tests | `tests/Feature/NotificationsTest.php`, im Verleih `NotificationsTest`, `ChatTest`, `RemindersTest` |
