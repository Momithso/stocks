# Module

Ein Modul ist ein Ordner unter `modules/` mit einer `module.json` und einem Service Provider. Der Kern findet es selbst; für ein neues Modul muss außerhalb seines Ordners nichts geändert werden. Das Beispielmodul `modules/Example` ist die Kopiervorlage.

## Inhalt

- [Aufbau](#aufbau)
- [module.json](#modulejson)
- [Ein- und Ausschalten](#ein--und-ausschalten)
- [Erweiterungspunkte](#erweiterungspunkte)
- [Frontend](#frontend)
- [Neues Modul anlegen](#neues-modul-anlegen)

## Aufbau

```text
modules/Example/
├─ module.json                  Beschreibung, Rechte, Abhängigkeiten
├─ ExampleServiceProvider.php   verbindet das Modul mit dem Kern
├─ Http/                        Controller (später auch Actions/, Models/)
├─ database/migrations/
├─ routes/web.php              Routen am PC und Handy
├─ routes/kiosk.php            Routen, die es auch am Kiosk gibt (optional)
├─ resources/js/pages/          Seiten, im Controller "Example::Index"
├─ resources/js/components/     Komponenten, z. B. Dashboard-Kacheln
├─ resources/js/icons.ts        genutzte Font-Awesome-Icons
└─ tests/
```

PHP-Klassen liegen im Namensraum `Modules\<Modul>` (`composer.json`: `"Modules\\": "modules/"`).

## module.json

```json
{
  "key": "example",
  "name": "Beispiel",
  "description": "Zeigt, wie ein Modul aufgebaut ist.",
  "version": "0.1.0",
  "provider": "Modules\\Example\\ExampleServiceProvider",
  "requires": [],
  "permissions": {
    "example.view": "Beispielseite sehen"
  },
  "start_roles": {
    "Vorstand": ["example.view"]
  }
}
```

| Feld | Bedeutung |
|---|---|
| `key` | Schlüssel des Moduls, auch Präfix seiner Rechte |
| `requires` | Schlüssel von Modulen, die aktiv sein müssen |
| `permissions` | Rechte mit Beschreibung; erscheinen in der Rollenverwaltung unter dem Modulnamen |
| `start_roles` | optional: Rechte, die die Startrollen (Name aus `config/stocks.php`) bei der Einrichtung bekommen. Bestehende Instanzen vergeben sie in der Rollenverwaltung; Admin hat sie immer |

`php artisan stocks:sync` (läuft beim Start des Containers und bei `stocks:install`) legt für neue Module einen ausgeschalteten Schalter an, trägt die Rechte ein und gibt sie der Admin-Rolle.

## Ein- und Ausschalten

- Unter Verwaltung → Module. Einschalten geht nur, wenn alle Module aus `requires` an sind; ausschalten nur, wenn kein aktives Modul es braucht (`ToggleModule`).
- Die Provider aller Module sind immer registriert und ihre Migrationen laufen immer. Ein ausgeschaltetes Modul verliert also keine Daten.
- Routen stehen hinter der Middleware `module:<key>` und antworten mit 404, solange das Modul aus ist. Menüeinträge, Kacheln und Kiosk-Kacheln mit dem Modulschlüssel werden dann nicht ausgeliefert.
- Warum Routen nicht weggelassen werden: Octane hält die Routenliste eines Workers bis zum Neustart. Ein ausgeschaltetes Modul wäre sonst bis zum Neustart erreichbar, ein eingeschaltetes nicht.
- Der Status liegt im Cache und wird beim Umschalten geleert.

## Erweiterungspunkte

Im `boot()` des Service Providers:

```php
public function boot(Navigation $navigation, DashboardTiles $dashboardTiles, SurfaceRoutes $surfaceRoutes): void
{
    $this->loadMigrationsFrom(__DIR__.'/database/migrations');

    Route::middleware(['web', 'auth', 'module:'.self::KEY])->group(__DIR__.'/routes/web.php');
    $surfaceRoutes->add('kiosk', __DIR__.'/routes/kiosk.php', self::KEY);

    $navigation->add('overview', 'Beispiel', 'example.index', 'flask', 'example.view', self::KEY);
    $navigation->addKioskTile('Beispiel', 'kiosk.example.index', 'flask', 'example.view', self::KEY);
    $dashboardTiles->add('Example::DashboardTile', 'example.view', self::KEY);
}
```

| Punkt | Klasse | Wirkung |
|---|---|---|
| Menü | `Navigation::add()` | Eintrag in Bereich `overview`, `inventory`, `loans` oder `admin`; `mobile: true` für die untere Leiste am Handy; `group: 'settings'` legt ihn in das aufklappbare Untermenü **Einstellungen**; `badge:` eine Closure, die `['text' => …, 'variant' => …]` oder `null` liefert (läuft bei jeder Seite, also nur Gespeichertes oder Gecachtes lesen) |
| Untermenü | `Navigation::addGroup()` | aufklappbare Gruppe in einem Bereich, z. B. `settings` im Bereich `admin` für selten genutzte Seiten |
| Oberfläche | `SurfaceRoutes::add('kiosk', Datei, Modul)` | Routendatei mit den Routen des Moduls, die es auch am Kiosk gibt (Liste des Erlaubten); siehe [KIOSK.md](KIOSK.md#seiten-für-den-kiosk-freigeben) |
| Kiosk-Kachel | `Navigation::addKioskTile()` | Kachel auf der Kiosk-Startseite; die Route ist eine Kiosk-Route (`kiosk.…`), `parameters:` hängt Abfrageparameter an |
| Dashboard | `DashboardTiles::add()` | Vue-Komponente `<Modul>::<Komponente>` aus `resources/js/components` |
| Protokoll | `AuditSubjects::register()` | Modelle mit dem Interface `AuditSubject` erscheinen lesbar im Protokoll |
| Einstellungen | `Settings::addDefaults()` und `Navigation::add(..., group: 'settings')` | eigene Schlüssel mit Präfix des Moduls und eine eigene Seite unter Verwaltung › Einstellungen; `Settings::module('<key>')` liefert alle Werte des Moduls |
| Reiter am Gegenstand | `ItemExtensions::addTab()` | Reiter neben „Verlauf“ auf der Seite eines Gegenstands; die Komponente bekommt `item` |
| Hinweis und Sperre am Gegenstand | `ItemExtensions::addInfo()` | siehe [Gegenstände](#gegenstände) |
| Öffentliche Box-Seite | `ItemExtensions::addPublicBoxSection()` | Abschnitt unter der Inhaltsliste auf `/s/{code}` |
| Demo-Daten | `DemoData::add()` | eigener Teil des Generators, siehe [DEMO.md](DEMO.md#neue-daten-in-einer-phase) |
| Ereignisse | Laravel-Events | Listener im Provider registrieren |

### Gegenstände

Ein Modul sagt über eine Klasse mit dem Interface `App\Support\Extensions\ItemInfoProvider`, was es über Gegenstände weiß. `notices(Collection $items, ?User $user)` gibt je Gegenstand eine Liste von Hinweisen zurück:

| Feld | Bedeutung |
|---|---|
| `text` | Satz für die Seite des Gegenstands und die Scan-Karte; leer, wenn der Hinweis nur eine Aktion bringt |
| `badge` | kurzer Text für die Gegenstandsliste, `null` für keinen |
| `variant` | `info`, `warning` oder `danger` |
| `href` | Link zu mehr, z. B. zur Buchung; `null` auf einer Oberfläche ohne diese Seite (`Surface::link()`) |
| `lock` | `true`: Umlagern, Teilen und Ausmustern sind gesperrt, solange der Hinweis gilt; der Text nennt den Grund |
| `actions` | Knöpfe auf der Scan-Karte (`title`, `href`, `icon`); nur Aktionen, deren Seite es auf der Oberfläche gibt |

Der Kern fragt die Hinweise für eine ganze Seite der Liste auf einmal ab (`Table::prepareRows()`). Für Sperren ruft er `ItemExtensions::lockOf()` mit `$user = null`; der Provider kann dann alles außer der Sperre weglassen. Ein Modul, das einen Gegenstand selbst wegnimmt (Ausgabe), nutzt `MoveItem::takeAway()`, das keine Sperren prüft.

`reservations(Item $item)` nennt Buchungen, die später mit dem Gegenstand rechnen, als Sätze (z. B. „B-2026-0042, ab 14.11., 16:00 Uhr.“). Ausmustern, Verbrauchen, Abteilen und ein Zustand, der als Schaden gilt, fragen dann vorher nach: Die Controller rufen `ItemExtensions::ensureConfirmed()`, das ohne `confirmed` mit dem Fehler `confirm` abbricht; die Seite zeigt ihn mit „Ja, trotzdem“ (`InventoryReservedConfirm`) und schickt den Vorgang bestätigt noch einmal. Die Actions selbst prüfen das nicht, damit Module wie der Verleih ihre eigenen Buchungen ohne Rückfrage bearbeiten.

### Öffentliche Box-Seite

Eine Klasse mit `App\Support\Extensions\PublicBoxSection` liefert für eine Box die Props ihrer Vue-Komponente oder `null`, dann fehlt der Abschnitt. Die Komponente bekommt zusätzlich `checked` (die auf dem Gerät abgehakten Nummern) und `total`. Alles ist ohne Anmeldung sichtbar; eigene Routen dafür registriert das Modul ohne `auth` (Beispiel: `modules/Loans/routes/public.php`).

## Frontend

- Seiten heißen im Controller `<Modul>::<Seite>` und liegen in `modules/<Modul>/resources/js/pages`. Das Layout kommt von der Oberfläche: am Kiosk das Kiosk-Layout, sonst `AppLayout`.
- Seiten und Komponenten, die auch am Kiosk laufen, bauen jede Adresse mit `url()` aus `useSurface()` (`url(\`/loans/${id}/issue\`)`), auch für `fetch`. Links auf Seiten, die der Kiosk nicht hat, blenden sie dort mit `kiosk` aus.
- Modulseiten nutzen die Komponenten des Kerns (`UiButton`, `UiCard` …) ohne Import.
- Icons, die das Modul nutzt, stehen in `resources/js/icons.ts` des Moduls.
- In Tests prüft `assertInertia()` nur Seiten des Kerns auf Existenz; für Modulseiten `component('Example::Index', false)` verwenden.

## Neues Modul anlegen

1. `modules/Example` kopieren und umbenennen, z. B. nach `modules/Loans`.
2. In `module.json` Schlüssel, Name, Beschreibung, Provider und Rechte anpassen.
3. Namensraum und Klassennamen von `Example` auf den neuen Namen ändern.
4. `composer dump-autoload` und `php artisan migrate && php artisan stocks:sync` ausführen.
5. Unter Verwaltung → Module einschalten und einer Rolle die Rechte geben.
6. Die README des Moduls schreiben: Abläufe, Tabellen, Routen, Rechte.

Ein Modul mit eigenen Layouts legt sie in `resources/js/layouts/`; eine Oberfläche nennt ihr Layout als `<Modul>::<Layout>`.

Tests mit einer gemeinsamen Basisklasse im Modul brauchen einen Eintrag unter `autoload-dev` in `composer.json` (`"Modules\\Loans\\Tests\\": "modules/Loans/tests/"`), weil der Ordner `tests` klein geschrieben ist.
