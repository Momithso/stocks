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
| `inventory.*`, `locations.manage`, `inventory.options.manage` | Inventar, siehe [INVENTORY.md](INVENTORY.md#rechte) |
| `labels.print`, `labels.manage` | Etiketten und Drucker, siehe [LABELS.md](LABELS.md#rechte) |
| `loans.*` | Modul Verleih, siehe [LOANS.md](LOANS.md#rechte) |

**Admin** ist eine Systemrolle: Sie hat immer alle Rechte, auch die später hinzugefügter Module (`stocks:sync`), und lässt sich weder ändern noch löschen. Die übrigen Startrollen (`config/stocks.php`) entstehen einmal bei der Einrichtung und sind danach frei änderbar. Module geben ihnen eigene Rechte über `start_roles` in der `module.json` mit.

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
| `organization.logo_path`, `organization.favicon_path`, `organization.print_logo_path` | Logo, Favicon und Druck-Logo auf S3, siehe unten |
| `organization.address` | Anschrift, eine Zeile je Adresszeile; Briefkopf der Box-Inhaltsliste |
| `legal.imprint_url`, `legal.privacy_url`, `legal.contact_email` | Rechtliche Links |
| `mail.from_name`, `mail.from_address` | Absender aller E-Mails; ohne Adresse gilt `MAIL_FROM_ADDRESS` |
| `mail.host`, `mail.port`, `mail.username`, `mail.password`, `mail.encryption` | Mailserver (SMTP); das Passwort verschlüsselt mit `APP_KEY`. Ohne Server gelten die `MAIL_*`-Variablen, siehe unten |
| `setup.completed_at` | Zeitpunkt der Einrichtung; ohne ihn leitet jede Seite auf `/setup` |
| `demo.seeded_at`, `demo.labels` | Zeitpunkt der Demo-Daten und die Codes des Testetiketten-Blatts, siehe [DEMO.md](DEMO.md); leer in einer echten Instanz |

**E-Mail** (Verwaltung › Einstellungen › E-Mail, `settings.manage`): Server, Port, Verschlüsselung (STARTTLS oder SSL/TLS), Benutzer, Passwort und Absender. `App\Support\MailSettings::apply()` setzt sie vor jeder Anfrage (Middleware `ApplyMailSettings`) und jedem Job (`Queue::before`) als SMTP-Mailer; ist kein Server eingetragen, bleibt die Umgebung (`MAIL_MAILER`, `MAIL_HOST` …). Das Passwort geht nie an den Browser; ein leeres Feld behält das gespeicherte. Der **Status** ist eine echte Anmeldung am Server (Timeout 10 s), 5 Minuten im Cache und nach jedem Speichern oder „Erneut prüfen“ neu. Das Menü zeigt ihn als Badge am Eintrag E-Mail („verbunden“/„getrennt“, bei zugeklappter Gruppe ein roter Punkt); die Seite liest dafür nur den Cache und baut nie selbst eine Verbindung auf. „Test-Mail senden“ schickt eine kurze Mail an eine frei wählbare Adresse (Vorgabe: die eigene) und zeigt den Fehler des Servers, wenn sie nicht rausgeht.

Module bringen eigene Schlüssel mit (`Settings::addDefaults()`, Präfix = Modulschlüssel, z. B. `loans.buffer_hours`); sie liegen in derselben Tabelle, `Settings::module('loans')` liefert sie gesammelt.
| `inventory.number_prefix` | Präfix neuer Artikelnummern, siehe [INVENTORY.md](INVENTORY.md#artikelnummern) |
| `labels.base_url`, `labels.base_url_confirmed` | Adresse in den Codes der Etiketten und die vor dem ersten Druck bestätigte Adresse, siehe [LABELS.md](LABELS.md#kurzlink-und-code) |

**Logo und Favicon** lädt man unter Verwaltung › Einstellungen › Allgemein hoch (`settings.manage`). `App\Support\Branding` legt sie auf S3 unter `branding/` ab und merkt sich den Pfad in den Einstellungen; ein neues Bild ersetzt und löscht das alte. Ausgeliefert werden sie über `/branding/logo` und `/branding/favicon`, auch an Gäste (Anmeldeseite, Browser-Tab), mit einer Woche Browser-Cache; der Dateiname steckt in der Adresse, so erscheint ein neues Bild sofort. Ohne eigenes Favicon gilt `public/favicon.ico`, ohne Logo steht nur der Name. Das **Druck-Logo** (`print_logo`) kommt auf Etiketten und die Inhaltsliste einer Box (`Branding::printKind()`); ohne eigenes gilt das Logo. So kann am Bildschirm ein helles Logo stehen und auf Papier und dem Niimbot ein dunkles. Das Logo steht oben links in der Seitenleiste als Quadrat von 64 Pixeln neben dem Kurznamen (`LayoutBrand size="nav"`), in der oberen Leiste am Handy und am Kiosk klein neben dem Namen und auf der Anmeldeseite mittig über dem Namen als Quadrat von 250 Pixeln (auf schmalen Bildschirmen 60 % der Breite). Am besten passt ein quadratisches Logo. Logo: PNG, JPG oder WebP bis 2 MB; Favicon: PNG oder ICO bis 512 KB; SVG ist verboten, weil es Skripte enthalten kann.

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
