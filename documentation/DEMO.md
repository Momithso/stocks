# Demo-Daten und Testetiketten

Die Demo-Daten werden nicht von Hand geschrieben, sondern erzeugt: Ein Generator kombiniert kurze Listen von Bausteinen (Gerätearten, Hersteller, Räume, Vor- und Nachnamen, Anlässe) nach Regeln. Kein einzelner Gegenstand steht ausgeschrieben im Code. Dazu gehört ein A4-Blatt mit 24 Testetiketten, mit dem sich alle Abläufe mit Kamera oder Handscanner durchspielen lassen, ohne erst Etiketten zu drucken.

## Inhalt

- [Aufruf](#aufruf)
- [Konten](#konten)
- [Was erzeugt wird](#was-erzeugt-wird)
- [Fester Startwert](#fester-startwert)
- [Testetiketten-Blatt](#testetiketten-blatt)
- [Neue Daten in einer Phase](#neue-daten-in-einer-phase)
- [Code](#code)

## Aufruf

```bash
php artisan migrate:fresh --seed      # leert die Datenbank und erzeugt die Demo-Daten
php artisan stocks:demo               # dasselbe; --seed=… für einen anderen Startwert
php artisan stocks:demo-sheet         # schreibt documentation/demo/testetiketten.pdf neu
```

`stocks:demo` löscht alle Daten. In einer Produktivinstanz (`APP_ENV=production`) verweigert der Befehl ohne `--force`. Zeiten der Buchungen sind relativ zu heute; es gibt also immer etwas, das heute abgeholt wird, und etwas Überfälliges.

## Konten

Alle mit Passwort „correct horse battery“ und Kiosk-PIN 482915.

| Konto | Rolle |
|---|---|
| `admin@example.com` | Admin |
| `demo1@example.com` | Vorstand |
| `demo2@example.com` | FSR-Mitglied (auch verantwortlich für Eigenbedarf) |
| `demo3@example.com` | Freies Mitglied |

Alle Module außer dem Beispielmodul sind eingeschaltet.

## Was erzeugt wird

**Kern (Inventar und Etiketten, `App\Support\Demo\InventoryDemo`):** 60 Standorte auf allen vier Ebenen (2 Adressen, 6 Räume, 16 Lagerplätze, 36 Fächer), Kategorien mit Unterkategorien, 30 Boxen (drei davon in anderen Boxen) und rund 400 Gegenstände. Pflichtfälle, je mindestens zweimal: Einzelstück, Mengenartikel, Verbrauchsmaterial, Box, Box in Box, abgeteilter Teil, defekt (mit Zustandswechsel), ausgemustert, ohne Platz, nicht am Stammplatz, ohne Etikett (jeder zehnte), mit Hinweis fürs Etikett, mit Fotos, reservierte Nummern. Fotos sind erzeugte farbige Bilder mit Text; ist S3 nicht erreichbar, fehlen sie einfach.

**Verleih (`Modules\Loans\Support\LoansDemo`):** 80 Kontakte (vollständige und unvollständige, privat und je Fachschaft), 12 Veranstaltungen und 120 Buchungen: 40 abgeschlossen, 6 mit Mängeln (ein Teil davon abgeschlossen), 6 storniert, 4 ungeprüft angenommen, 14 ausgegeben (davon überfällige, Eigenbedarf, eine Teilrückgabe, eine Teilausgabe mit offenem Rest und einem Mangel bei der Ausgabe und eine überfällige Teilausgabe, deren Rest nicht abgeholt wurde), 30 bestätigt (drei heute abzuholen, eine nicht abgeholt) und 20 Entwürfe (ein Teil kollidiert mit einer bestätigten Buchung, andere merken Gegenstände vor). Dazu Box-Kontrollen per Pack-PIN und Gegenstände, die zurück, aber noch nicht eingeräumt sind.

**Verleihseite und Nachrichten (Phase 5, ebenfalls `LoansDemo`):** Chat-Verläufe an drei Buchungen (die ausgegebene Buchung des Testblatts mit einer ungelesenen Antwort des Entleihers, die überfällige, eine abgeschlossene), abgehakte Zeilen der Checkliste, eine offene und eine angenommene Verlängerungsanfrage, ein Protokoll mit Mail und Push gemischt (auch Überfällig Stufe 1 und 2 und die Nachricht an den FSR), eine gesperrte Verleihseite, Einstellungen der Verleihseite an einer Buchung und zwei Demo-Konten mit eigenen Benachrichtigungs-Einstellungen. Die Organisation hat eine Telefonnummer. Keine Push-Abos; der Generator verschickt nichts (`NotificationEvents::quietly()`). Erinnerungen, die direkt nach dem Seeden fällig wären, trägt er als „ausgelöst (Demo, nicht verschickt)“ ins Protokoll ein (`Reminders::run(send: false)`), damit der Scheduler danach nichts nachholt; spätere gehen normal raus. Vorher leert er die Warteschlange (`queue:clear`), damit keine Aufträge der alten Daten mit den neuen verschickt werden.

**Kiosk (`Modules\Kiosk\Support\KioskDemo`):** zwei gekoppelte Geräte, „Lager-Tablet“ (Android, druckt direkt) und „iPad Ausgabe“ (iPad, lädt das Etikett als Bild). Ihre Tokens kommen aus dem Startwert; die Kopplungs-Adressen und QR-Codes stehen unter Verwaltung › Einstellungen › Kiosk-Geräte. Am Kiosk entsperren alle Demo-Konten außer dem freien Mitglied mit der PIN 482915. Der Teil läuft als letzter und ändert deshalb keine Nummern des Testblatts.

Jede Buchung läuft durch die Actions zu ihrer eigenen Zeit: Der Generator stellt die Uhr auf die Abholung und auf die Rückgabe (`Carbon::setTestNow`), so tragen Dokumente, Verlauf und Änderungsprotokoll echte Daten. Vergangene Buchungen teilen sich Gegenstände (sie belegen nichts mehr), laufende und künftige haben eigene.

## Fester Startwert

Alle Zufallsentscheidungen gehen über `DemoContext` mit einem festen Startwert (`DemoGenerator::SEED`, Mersenne Twister von PHP). Derselbe Startwert ergibt dieselben Gegenstände in derselben Reihenfolge und damit dieselben Nummern. Nur so bleibt das Testblatt nach jedem `migrate:fresh --seed` gültig. Wer Bausteine oder Reihenfolge ändert, erzeugt das Blatt neu.

## Testetiketten-Blatt

`documentation/demo/testetiketten.pdf`, Format DIN A4:

- Seite 1: 24 Etiketten (3 × 8, je 70 × 37 mm) mit QR-Code, Nummer, Name und wofür das Etikett gedacht ist.
- Seite 2: die Prüfliste: was jedes Etikett ist und was beim Scan passieren soll.

Ausgewählt sind die Pflichtfälle, nicht der ganze Bestand: zwei Fächer, Einzelstück, Mengenartikel, Verbrauchsmaterial, defekter und ausgemusterter Gegenstand, abgeteilter Teil, Gegenstand mit Hinweis und Fotos, Box mit Inhalt, Box in Box, reservierte und unbekannte Nummer; aus dem Verleih die Box, ein Einzelstück und ein Mengenartikel einer heute abzuholenden Buchung, eine ausgegebene Box und ein ausgegebener Gegenstand, ein überfälliger Gegenstand, die Verleihseite der ausgegebenen Buchung (seit Phase 5 statt Eigenbedarf), eine ungeprüft zurückgenommene Box, ein noch nicht eingeräumter Gegenstand, ein vermisster Gegenstand und ein in einem Entwurf vorgemerkter Gegenstand, der zugleich als fremder Gegenstand für die Warnung bei der Ausgabe dient (so bleibt das Blatt bei 24 Etiketten).

Die Etiketten wählen die Teile des Generators selbst (`DemoContext::label()`); gespeichert sind sie in der Einstellung `demo.labels`. Das Blatt entsteht auf dem Server mit dompdf und `bacon-qr-code` (`App\Support\Demo\TestSheet`), als einfaches Raster und nicht mit den Vorlagen des Etiketten-Editors.

Die Codes sind Kurzlinks, bis auf die Verleihseite: ihr Code ist der Link der Seite (`label(..., url: …)`). Dieser Link hängt an `APP_KEY`; der im Repository liegende Code öffnet die Seite nur in der Instanz, die das Blatt erzeugt hat. Das Blatt aus der App stimmt immer. Im Repository liegt das Blatt mit `http://localhost`; in der App gibt es unter Verwaltung › Einstellungen › Etiketten (und auf „Etiketten auf Vorrat“) den Knopf „Testetiketten (Demo)“, der das Blatt mit der Adresse der Instanz erzeugt, damit auch die Handykamera die Codes öffnet. Der Knopf erscheint nur, wenn die Instanz Demo-Daten hat (`demo.seeded_at`). Recht: `labels.print`.

## Neue Daten in einer Phase

Regel für alle Phasen (steht auch in `CLAUDE.md`): Jede Phase erweitert den Generator um ihre neuen Daten und Zustände und erzeugt das Testblatt neu.

1. Ein Teil je Baustein: Der Kern meldet seine Teile in `AppServiceProvider` an, ein Modul seinen eigenen in seinem Service Provider (`DemoData::add(Klasse, Modul, Reihenfolge)`). Ein ausgeschaltetes Modul trägt nichts bei.
2. Der Teil implementiert `App\Support\Demo\DemoPart` und nimmt alle Zufälle aus dem `DemoContext` (`int`, `chance`, `pick`, `weighted`, `shuffle`), nie aus `rand()` oder Faker.
3. Was spätere Teile brauchen, markiert er mit `tag()`; spätere Teile holen es mit `tagged()` oder `take()`.
4. Pflichtfälle zuerst, dann Zufall bis zum Umfang. Für das Testblatt `label(Code, Name, wofür, was beim Scan passieren soll)`, optional mit eigener Adresse (`url:`); insgesamt höchstens 24.
5. `php artisan stocks:demo && php artisan stocks:demo-sheet` und das PDF mit einchecken. Die Tests in `tests/Feature/Demo`, `modules/Loans/tests/DemoTest.php` und `modules/Kiosk/tests/KioskDemoTest.php` prüfen Umfang, Pflichtfälle, gleiche Nummern bei gleichem Startwert und dass es jeden Code des Blatts gibt.

## Code

| Was | Wo |
|---|---|
| Ablauf, Konten | `app/Support/Demo/DemoGenerator.php`, `database/seeders/DatabaseSeeder.php` |
| Zufall, Tags, Etiketten | `app/Support/Demo/DemoContext.php` |
| Inventar | `app/Support/Demo/InventoryDemo.php` |
| Verleih | `modules/Loans/Support/LoansDemo.php` |
| Kiosk | `modules/Kiosk/Support/KioskDemo.php` |
| Testblatt | `app/Support/Demo/TestSheet.php`, `resources/views/pdf/demo-sheet.blade.php`, Download `App\Http\Controllers\Labels\DemoSheetController` |
| Befehle | `routes/console.php` (`stocks:demo`, `stocks:demo-sheet`) |
