# Kern

Der Kern von Stocks verwaltet Konten, Rollen, Module, Einstellungen und Kiosk-Geräte. Fachliche Funktionen wie Inventar und Verleih kommen ab Phase 1 dazu; sie hängen sich über die Erweiterungspunkte in [MODULES.md](MODULES.md) ein.

## Inhalt

- [Aufbau](#aufbau)
- [Anmeldung und Konten](#anmeldung-und-konten)
- [Rollen und Rechte](#rollen-und-rechte)
- [Einstellungen](#einstellungen)
- [Kiosk](#kiosk)
- [Änderungsprotokoll](#änderungsprotokoll)
- [Oberfläche](#oberfläche)
- [Datenbank](#datenbank)
- [Routen](#routen)
- [Tests](#tests)

## Aufbau

| Ordner | Inhalt |
|---|---|
| `app/Actions` | Ein fachlicher Vorgang je Klasse, Methode `handle()`. Hier stehen die Regeln. |
| `app/Http/Controllers` | Nehmen die Anfrage an, prüfen die Eingaben mit `validate()`, rufen eine Action, geben die Seite oder eine Weiterleitung zurück. |
| `app/Http/Middleware` | Einrichtung, deaktivierte Konten, Module, Kiosk |
| `app/Models` | Eloquent-Modelle. Jedes Modell mit fachlicher Bedeutung wird auditiert. |
| `app/Support` | Bausteine ohne Fachbezug: Modul-Registry, Erweiterungspunkte, Einstellungen, Tabellen, Rechte-Regel, Kiosk-Sitzung, QR-Codes |
| `modules/` | Module, siehe [MODULES.md](MODULES.md) |
| `resources/js/components/ui` | Designsystem, siehe `/styleguide` |
| `resources/js/pages` | Inertia-Seiten des Kerns |

Rechte werden in Routen mit `can:<recht>` geprüft. Regeln, die über ein einzelnes Recht hinausgehen (z. B. die Vergaberegel), prüfen die Actions, damit sie auch bei direkten Anfragen gelten.

## Anmeldung und Konten

- Konten entstehen nur per Einladung (`InviteUser`). Der Link gilt `stocks.invitation_lifetime_days` Tage und einmal; gespeichert wird nur der SHA-256-Hash des Tokens. Eine neue Einladung an dieselbe Adresse ersetzt die alte.
- `AcceptInvitation` legt das Konto mit den Rollen der Einladung an. Die Rollen wurden beim Einladen gegen die Rechte der einladenden Person geprüft.
- Anmeldung mit E-Mail-Adresse (klein geschrieben gespeichert) und Passwort. Fünf Fehlversuche je Adresse und IP-Adresse sperren für eine Minute.
- Passwörter: mindestens 12 Zeichen. Mit `CHECK_LEAKED_PASSWORDS=true` werden zusätzlich bekannte geleakte Passwörter abgelehnt (Abfrage bei haveibeenpwned.com).
- „Passwort vergessen“ nutzt den Passwort-Broker von Laravel. Die Antwort ist immer gleich, ob es die Adresse gibt oder nicht.
- Deaktivierte Konten können sich nicht anmelden; eine laufende Sitzung endet bei der nächsten Anfrage (`EnsureUserIsActive`).
- Im Profil ändert jede Person Name, Passwort (mit dem aktuellen Passwort) und Kiosk-PIN.

## Rollen und Rechte

Der Code prüft nur Rechte, nie Rollennamen. Rechte heißen `bereich.tätigkeit` und stehen mit Beschreibung in `config/stocks.php` (Kern) bzw. in der `module.json` eines Moduls.

| Recht | Erlaubt |
|---|---|
| `users.view` | Kontenliste sehen |
| `users.invite` | Konten einladen, Einladungen zurückziehen |
| `users.manage` | Konten bearbeiten, deaktivieren, PIN zurücksetzen |
| `users.delete` | Konten löschen |
| `roles.manage` | Rollen anlegen, ändern, löschen und zuweisen |
| `modules.manage` | Module ein- und ausschalten |
| `settings.manage` | Einstellungen ändern, Styleguide |
| `kiosk.devices.manage` | Kiosk-Geräte koppeln und sperren |
| `kiosk.use` | Am Kiosk mit PIN anmelden |
| `audit.view` | Änderungsprotokoll lesen |

**Admin** ist eine Systemrolle: Sie hat immer alle Rechte, auch die später hinzugefügter Module (`stocks:sync`), und lässt sich weder ändern noch löschen. Die übrigen Startrollen (`config/stocks.php`) entstehen einmal bei der Einrichtung und sind danach frei änderbar.

**Vergaberegel** (`App\Support\Permissions\GrantCheck`): Niemand vergibt mehr, als er selbst hat.

- Eine Rolle bekommt nur Rechte, die die handelnde Person selbst hat (`SaveRole`).
- Eine Rolle mit Rechten, die der handelnden Person fehlen, kann sie weder ändern noch löschen.
- Rollen werden nur zugewiesen oder entzogen, wenn die handelnde Person alle Rechte der Rolle hat; dafür braucht sie außerdem `roles.manage`. Das gilt auch für Rollen in Einladungen.
- Konten werden nur bearbeitet, deaktiviert, gelöscht oder in der PIN zurückgesetzt, wenn die handelnde Person alle Rollen des Kontos vergeben könnte. Das eigene Konto wird dort nie bearbeitet.
- Das letzte aktive Admin-Konto bleibt Admin, aktiv und bestehen.
- Rollen, die noch Konten haben, lassen sich nicht löschen.

## Einstellungen

`App\Support\Settings` liest und schreibt die Tabelle `settings`. Jeder Schlüssel hat einen Vorgabewert in `Settings::DEFAULTS`, alle Werte liegen im Cache und der Cache wird bei jeder Änderung geleert. Jede Änderung wird auditiert.

| Schlüssel | Bedeutung |
|---|---|
| `organization.name`, `organization.short_name` | Name in Menü, E-Mails, Titel |
| `organization.accent_color` | Akzentfarbe, wird in `app.blade.php` als CSS-Variable gesetzt |
| `legal.imprint_url`, `legal.privacy_url`, `legal.contact_email` | Rechtliche Links |
| `mail.from_name`, `mail.from_address` | Absender aller E-Mails; ohne Adresse gilt `MAIL_FROM_ADDRESS` |
| `setup.completed_at` | Zeitpunkt der Einrichtung; ohne ihn leitet jede Seite auf `/setup` |

## Kiosk

Ein Tablet wird zum Kiosk, wenn es einmal die Kopplungs-Adresse eines Kiosk-Geräts öffnet (Verwaltung → Kiosk-Geräte, QR-Code). Danach speichert es ein Cookie mit Geräte-ID und Token (400 Tage, nur für `/kiosk`). Ein neues Token oder Löschen sperrt das Tablet sofort aus. Der Aufbau stammt aus dem Strichlistensystem von Portals.

- `/kiosk` zeigt alle aktiven Konten mit `kiosk.use`. Antippen, PIN eingeben (`config('kiosk.pin_length')`, sechs Ziffern).
- Die Kiosk-Sitzung (`App\Support\Kiosk\KioskSession`) ist von der normalen Anmeldung getrennt. Sie endet nach `kiosk.session_timeout` Sekunden ohne Aktivität, beim Sperren und wenn das Konto deaktiviert wird oder `kiosk.use` verliert.
- Falsche PINs sind je Person (`kiosk.pin_max_attempts` pro Minute) und je Gerät (`kiosk.device_max_failed_pins` in `kiosk.device_lock_seconds`) begrenzt, weil alle Tablets dieselbe IP-Adresse haben können.
- Die PIN wird gehasht gespeichert und nirgends ausgeliefert. Leicht zu erratende PINs (gleiche Ziffern, auf- oder absteigende Folgen, `kiosk.trivial_pins`) werden abgelehnt (`App\Rules\KioskPin`).
- Kiosk-Routen von Modulen nutzen die Middleware `kiosk.device` und `kiosk`; die entsperrende Person ist das Request-Attribut `kioskUser`.

## Änderungsprotokoll

`owen-it/laravel-auditing` schreibt jede Änderung auditierter Modelle. Besondere Ereignisse (`rolesUpdated`, `permissionsUpdated`, `pinReset`) schreibt `AuditRecorder`. Die Seite Verwaltung → Protokoll formatiert die Einträge mit `AuditLogFormatter`; Typ, Name und Feldbezeichnungen liefern die Modelle selbst über das Interface `AuditSubject`. Passwort, PIN und Tokens werden nie protokolliert.

## Oberfläche

- **Layouts:** `AppLayout` (Seitenleiste am PC, Leiste unten am Handy), `AuthLayout` (Anmeldung, Einrichtung), `KioskLayout`. Das Layout ergibt sich aus dem Seitennamen (`resources/js/app.ts`).
- **Menü:** kommt vom Server (`App\Support\Extensions\Navigation`), gruppiert nach Aufgabe: Übersicht, Inventar, Verleih, Verwaltung. Höchstens zwei Ebenen; Einträge ohne Recht und leere Bereiche erscheinen nicht. Am Handy zeigt die untere Leiste die Einträge mit `mobile: true` (höchstens vier) und „Mehr“.
- **Farben:** Tokens als CSS-Variablen in `resources/css/app.css` (`page`, `surface`, `surface-muted`, `line`, `ink`, `ink-muted`, `accent`, `accent-hover`). Komponenten nutzen nur diese Namen.
- **Komponenten:** `resources/js/components/ui`, automatisch als `Ui…` registriert. Alle stehen auf `/styleguide`.
- **Icons:** Font Awesome Solid. Genutzte Icons stehen in `resources/js/icons.ts` bzw. `modules/<Modul>/resources/js/icons.ts`.
- **Schrift:** Inter wird mitgeliefert (`@fontsource-variable/inter`); es werden keine Schriften oder Skripte von fremden Servern geladen.

## Datenbank

| Tabelle | Zweck |
|---|---|
| `users` | Konten (`firstname`, `lastname`, `email`, `password`, `pin`, `is_disabled`, `last_login_at`) |
| `password_reset_tokens` | Passwort zurücksetzen |
| `invitations`, `invitation_role` | Offene Einladungen und ihre Rollen |
| `roles`, `permissions` und Zwischentabellen | `spatie/laravel-permission`; `roles` hat zusätzlich `description` und `is_system` |
| `modules` | Schalter je Modul |
| `settings` | Einstellungen (JSON-Wert) |
| `kiosk_devices` | Gekoppelte Tablets, Token verschlüsselt |
| `audits` | Änderungsprotokoll |
| `cache`, `cache_locks`, `jobs`, `failed_jobs` | Laravel-Betrieb |

## Routen

| Bereich | Adressen |
|---|---|
| Einrichtung | `/setup` |
| Anmeldung | `/login`, `/logout`, `/forgot-password`, `/reset-password/{token}`, `/invitation/{token}` |
| Angemeldet | `/dashboard`, `/profile`, `/styleguide` |
| Verwaltung | `/admin/users`, `/admin/roles`, `/admin/modules`, `/admin/kiosk-devices`, `/admin/settings`, `/admin/audit-log` |
| Kiosk | `/kiosk/pair/{device}`, `/kiosk`, `/kiosk/unlock`, `/kiosk/home`, `/kiosk/logout` |

`php artisan route:list --except-vendor` zeigt alle Routen mit Namen.

## Tests

```bash
php artisan test --compact
```

Die Tests laufen mit SQLite im Speicher (`phpunit.xml.dist`). `tests/TestCase.php` richtet vor jedem Test eine Instanz mit den Startrollen ein und bietet `admin()`, `userWithRoles()`, `userWithPermissions()` und `activateModule()`. Tests von Modulen liegen in `modules/<Modul>/tests`.
