# Kiosk

Das Modul `modules/Kiosk` (Schlüssel `kiosk`) macht ein Tablet zum Kiosk: Es steht fest im Lager oder an der Ausleihe, wer etwas tun will, tippt seinen Namen an und gibt seine PIN ein. Danach gibt es dieselben Vorgänge wie am PC, mit großen Knöpfen und ohne Menü: Scannen, Umlagern, Zustand, Verbraucht, Etiketten drucken, Ausgabe, Rückgabe, Prüfung und Einräumen. Das Modul ist optional; ohne Tablet bleibt es aus.

## Inhalt

- [Einrichten](#einrichten)
- [Sitzung](#sitzung)
- [Plattformen](#plattformen)
- [Vorgänge am Kiosk](#vorgänge-am-kiosk)
- [Verleih am Kiosk](#verleih-am-kiosk)
- [Oberfläche im Code](#oberfläche-im-code)
- [Seiten für den Kiosk freigeben](#seiten-für-den-kiosk-freigeben)
- [Rechte](#rechte)
- [Einstellungen](#einstellungen)
- [Sicherheit](#sicherheit)
- [Code](#code)

## Einrichten

1. Verwaltung › Module › „Kiosk“ einschalten.
2. Verwaltung › Einstellungen › Kiosk-Geräte: Gerät mit Name und Plattform anlegen.
3. Auf dem Tablet den QR-Code scannen oder die Kopplungs-Adresse öffnen. Das Tablet speichert ein Cookie mit Geräte-ID und Token (400 Tage, nur für `/kiosk`) und zeigt den Sperrbildschirm.
4. Den Kiosk als Web-App installieren, dann startet er im Vollbild auf dem Sperrbildschirm. Der Sperrbildschirm sagt je Plattform, wie das geht, solange der Kiosk im Browser läuft.
5. Wer den Kiosk nutzt, setzt im eigenen Profil eine PIN (sechs Ziffern) und braucht das Recht `kiosk.use`.

„Neu koppeln“ erzeugt ein neues Token, Löschen entfernt das Gerät; beides sperrt das Tablet sofort aus. Kamera und Direktdruck brauchen HTTPS. Koppeln beendet eine normale Anmeldung im Browser des Tablets, damit außerhalb von `/kiosk` niemand angemeldet bleibt. Der Kopplungs-Link bleibt gültig, bis jemand „Neu koppeln“ drückt; wer ihn sieht, kann damit weitere Geräte koppeln.

## Sitzung

- Der Sperrbildschirm `/kiosk` zeigt alle aktiven Konten mit `kiosk.use`. Antippen, PIN eingeben.
- Die Kiosk-Sitzung (`Modules\Kiosk\Support\KioskSession`) ist von der normalen Anmeldung getrennt. Für jede Anfrage am Kiosk meldet die Middleware `kiosk` die Person nur für diese Anfrage an (`Auth::setUser`) und vergisst sie danach. So sehen Controller, Rechte und Änderungsprotokoll die Person wie am PC, aber die PIN öffnet nicht das übrige Stocks.
- Die Kopfzeile zeigt die Person, die Restzeit (die letzten 20 Sekunden rot), „Verlängern“, „Startseite“ und „Sperren“. Jede Anfrage am Kiosk setzt die Zeit zurück, auch im Browser (jede Antwort startet die Uhr neu, auch mit derselben Restzeit); Tippen und Tasten verlängern höchstens alle 15 Sekunden (`POST /kiosk/extend`). Bei null sperrt der Kiosk. Ohne Verbindung läuft die Zeit weiter, ein Hinweis sagt es.
- Nach Ausgabe, Rückgabe und Prüfung geht der Kiosk zur Startseite und zeigt die Meldung des Vorgangs; die Person bleibt angemeldet, bis sie sperrt oder die Zeit abläuft. Mit der Einstellung „Nach jedem Vorgang sperren“ sperrt er stattdessen.
- Die Sitzung endet auch, wenn das Konto deaktiviert wird oder `kiosk.use` verliert.
- Falsche PINs sind je Person (`kiosk.pin_max_attempts` pro Minute) und je Gerät (`kiosk.device_max_failed_pins` in `kiosk.device_lock_seconds`) begrenzt, weil alle Tablets dieselbe IP-Adresse haben können.

## Plattformen

Jedes Gerät hat eine Plattform. Sie entscheidet über den Direktdruck auf den Niimbot-Drucker und den Hinweis zum Vollbild.

| Plattform | Direktdruck | Vollbild |
|---|---|---|
| Android-Tablet | ja, per Bluetooth oder USB (Chrome, Edge) | Chrome › „Zum Startbildschirm hinzufügen“ |
| iPad | nein, das Etikett wird als Bild geladen und in der Niimbot-App gedruckt | Safari › Teilen › „Zum Home-Bildschirm“ |
| PC oder Mac | ja (Chrome, Edge) | „Als App installieren“ oder F11 |
| Anderes Gerät | der Browser entscheidet | allgemeiner Hinweis |

## Vorgänge am Kiosk

Die Menüführung folgt üblichen Selbstbedienungs-Terminals: ein Startbildschirm mit einer einzigen Frage, eine Kachel je Aufgabe, ein linearer Ablauf mit sichtbarem Fortschritt, und „Zurück“, „Startseite“ und „Sperren“ immer an derselben Stelle.

1. **Sperrbildschirm:** „Wer bist du?“ – Namen antippen, PIN eingeben.
2. **Startseite:** „Was möchtest du tun?“ Die Kacheln sind nach Bereich gruppiert (Verleih, Lager). Jede sagt in einem Satz, was passiert, und zeigt, wie viel zu tun ist (z. B. „3 heute“, „2 überfällig“, „5 offen“).
3. **Auswahl:** bei Ausgabe und Rückgabe die Liste der Buchungen oder ein Scan.
4. **Ablauf in Schritten** mit dem Strahl oben (siehe [LOANS.md](LOANS.md#ausgabe)); unten immer „Zurück“ und „Weiter“. Unterschriften kommen erst, wenn das Material abgehakt ist.
5. **Ende:** Der Kiosk geht zur Startseite (oder sperrt, wenn eingestellt) und zeigt die Meldung des Vorgangs.

Die Kopfzeile hat auf jeder Unterseite „Zurück“ (als Web-App gibt es keinen Zurück-Knopf des Browsers), „Startseite“, die Restzeit, „Verlängern“ und „Sperren“.

| Kachel | Bereich | Seite | Recht |
|---|---|---|---|
| Material ausgeben | Verleih | Liste „Heute abholen“, dann die Ausgabe in Schritten | `loans.issue` |
| Material zurücknehmen | Verleih | Liste „Ausgegeben“, dann die Rückgabe in Schritten | `loans.issue` |
| Einräumen und prüfen | Verleih | Seite „Rückgaben“ (Einräumen, Prüfung offen) | `loans.issue` |
| Scannen und umlagern | Lager | `/kiosk/inventory/scan`: Scan-Karte, Umlagern, „Hierher umlagern“, Zustand, Verbraucht, Zurückbuchen, Etikett | `inventory.view`, zum Ändern `inventory.move` |
| Etikett (von der Scan-Karte) | – | `/kiosk/labels/print`: Vorschau und Druck; „Zurück“ bleibt am Kiosk, „Als Vorlage speichern“ fehlt | `labels.print` |

Eine Kachel meldet ein Modul mit `Navigation::addKioskTile(..., description:, section:, badge:)` an; `badge` ist eine Closure wie beim Menü und muss schnell sein.

Was der Kiosk nicht hat: Listen und Seiten von Gegenständen, Standorten und Buchungen, Anlegen, Bearbeiten, Ausmustern, Einstellungen und Verwaltung. Links dorthin fehlen am Kiosk (die Scan-Karte zeigt z. B. kein „Öffnen“). Die vollständige Liste steht in [INTERFACES.md](INTERFACES.md).

## Verleih am Kiosk

Mit dem Modul Verleih kommen drei Kacheln dazu:

- **Ausgabe:** Liste „Heute abholen“ (bestätigte Buchungen mit Abholung bis heute, eine verpasste Abholung markiert „Abholung verpasst“, dazu ausgegebene mit offenem Rest einer Teilausgabe, markiert „Rest nachausgeben“; beides nur, solange der Rückgabetermin nicht vorbei ist, `KioskLoanController::pickups()`). Antippen öffnet die Ausgabe bzw. Nachausgabe. Die Zahl auf der Kachel zählt die Abholungen von heute und die offenen Reste.
- **Rückgabe:** Liste „Ausgegeben“, überfällige markiert. Antippen öffnet die Rückgabe. Darunter **„Mit Mängeln“**: abgeschlossene Buchungen mit offenen Mängeln und ausgegebene, bei denen eine Teilrückgabe Mängel hatte.
- **Einräumen:** die Seite „Rückgaben“ mit „Noch nicht eingeräumt“, „Prüfung offen“ und „Mängel offen“. Ein Tipp auf einen Gegenstand öffnet ein Info-Fenster (wie die Scan-Karte, dazu Herkunft und Stammplatz); „Einräumen“ in der Zeile oder ein Scan wählt ihn aus, danach das Regal scannen.

Buchungen öffnen am Kiosk ein **Fenster** statt der Buchungsseite (`GET /kiosk/loans/{id}/details`, Komponente `LoanModal`): Positionen mit Mangel und Notiz, „Wieder da“ für Vermisstes (`loans.issue`), „Mängel abschließen“ mit Notiz (`loans.cancel`) und je nach Status „Rückgabe“ oder „Prüfen“.

Über beiden Listen steht ein Scanfeld. Die Buchungsnummer, eine Box oder ein Gegenstand der Buchung öffnet den passenden Schritt (`/kiosk/loans/find`): ausgegeben → Rückgabe, noch auszugeben (bestätigt oder Rest einer Teilausgabe) → Ausgabe, ungeprüft → Prüfung.

Ausgabe, Rückgabe und Prüfung sind die Seiten des PCs mit Unterschrift auf dem Bildschirm. Leihvertrag und Materialliste lassen sich vorher ansehen. Einen fremden Gegenstand in die Buchung nehmen und den Verantwortlichen ändern geht mit `loans.manage` wie am PC. Wer ausgibt, ist die Person am Kiosk. Ein angefangener Vorgang lässt sich am PC fortsetzen und umgekehrt, der Stand liegt auf dem Server.

## Oberfläche im Code

Der Kern kennt den Kiosk nicht; er kennt **Oberflächen** (`App\Support\Surface`). Die Middleware `kiosk.device` setzt für jede Anfrage unter `/kiosk` die Oberfläche als Request-Attribut, `kiosk` ergänzt Person und Restzeit:

| Schlüssel | Kiosk | Wofür |
|---|---|---|
| `name` | `kiosk` | `useSurface().kiosk` |
| `base` | `/kiosk` | Präfix für `url()` |
| `route_prefix` | `kiosk.` | `Surface::route()`, `Surface::has()`, `Surface::link()` |
| `layout` | `Kiosk::KioskLayout` | `SurfaceLayout` wählt danach das Layout |
| `done_route` | `kiosk.done` | Ziel von `Surface::finish()` |
| `platform`, `direct_print` | vom Gerät | Druckseite |
| `person`, `expires_in`, `lock_after_task` | aus der Sitzung | Kopfzeile, Zeitgeber |

Im Frontend ist alles außer `route_prefix`, `done_route` und `manifest` als geteiltes Prop `surface` da (am PC `null`).

- **Adressen:** `const { url, kiosk } = useSurface();` und dann `url(\`/loans/${id}/issue\`)`. Am PC bleibt die Adresse, am Kiosk wird `/kiosk` vorangestellt. Das gilt für Links, Formulare, `router` und `fetch`.
- **Server:** `Surface::route('loans.returns')` liefert die Adresse der Oberfläche, falls sie die Route hat, sonst die normale. `Surface::link()` liefert `null`, wenn die Oberfläche die Seite nicht hat (Links auf der Scan-Karte). `Surface::finish($redirect)` beendet einen Vorgang: am Kiosk über `/kiosk/done`, sonst mit `$redirect`.
- **Menü:** Am Kiosk ist das geteilte Prop `navigation` leer.

## Seiten für den Kiosk freigeben

Eine Route gibt es am Kiosk nur, wenn sie in der Datei `routes/kiosk.php` ihres Moduls steht (für den Kern `modules/Kiosk/routes/kiosk.php`). Das Modul meldet die Datei an:

```php
$surfaceRoutes->add('kiosk', __DIR__.'/routes/kiosk.php', self::KEY);
```

Das Modul Kiosk registriert alle Dateien unter `/kiosk` mit dem Namenspräfix `kiosk.` und den Middlewares `web`, `module:kiosk`, `module:<Modul>`, `kiosk.device` und `kiosk`. Die Einträge sind dieselben Controller wie am PC mit denselben `can:`-Regeln; nur `auth` fehlt, weil die Middleware `kiosk` die Person anmeldet. Ist das Modul Kiosk aus, registriert niemand die Dateien; ist das Modul der Route aus, antwortet sie mit 404.

Checkliste für eine neue Seite am Kiosk:

1. Route in `routes/kiosk.php` des Moduls eintragen.
2. In der Seite und ihren Komponenten jede Adresse mit `url()` bauen; Links auf Seiten ohne Kiosk-Route mit `kiosk` ausblenden.
3. Im Controller statt `to_route()` auf Seiten, die der Kiosk nicht hat, `Surface::route()`, `Surface::link()` oder `Surface::finish()` nehmen.
4. Die Dateien in `tests/Feature/SurfacePathsTest.php` eintragen; der Test findet feste Adressen.
5. Kachel mit `Navigation::addKioskTile(Titel, 'kiosk.<route>', Icon, Recht, Modul)`, wenn die Seite ein Einstieg ist.
6. [INTERFACES.md](INTERFACES.md) nachziehen.

Das Beispielmodul zeigt den kleinsten Fall: `modules/Example/routes/kiosk.php` gibt `/example` am Kiosk frei, dieselbe Seite läuft dort im Kiosk-Layout.

## Rechte

| Recht | Erlaubt | Freies Mitglied | FSR-Mitglied | Vorstand | Admin |
|---|---|---|---|---|---|
| `kiosk.use` | Am Kiosk mit PIN anmelden | – | ✓ | ✓ | ✓ |
| `kiosk.devices.manage` | Kiosk-Geräte koppeln, sperren und einstellen | – | – | – | ✓ |

Am Kiosk gelten zusätzlich die Rechte der Vorgänge, wie am PC.

## Einstellungen

Unter Verwaltung › Einstellungen › Kiosk-Geräte, Karte „Sitzung“ (`kiosk.devices.manage`):

| Schlüssel | Bedeutung |
|---|---|
| `kiosk.session_timeout` | Sekunden ohne Bedienung bis zur Sperre, 30 bis 3600; Vorgabe aus `config/kiosk.php` (120) |
| `kiosk.lock_after_task` | nach Ausgabe, Rückgabe und Prüfung sperren oder zur Startseite gehen (Vorgabe) |

Länge der PIN, Fehlversuche und leicht zu erratende PINs stehen in `config/kiosk.php`. Die PIN selbst gehört zum Konto und damit zum Kern (`users.pin`, `App\Rules\KioskPin`); das Profil zeigt das Feld nur, wenn das Modul an ist und die Person `kiosk.use` hat.

## Sicherheit

- Gerätetoken verschlüsselt in der Datenbank, Vergleich mit `hash_equals`; die Kopplung hat ein Rate Limit.
- PIN gehasht, nie ausgeliefert; Sperre je Person und je Gerät.
- Nach dem Entsperren eine neue Session-ID, damit eine vorher gesehene ID nichts mehr nützt.
- Die Person ist nur für die einzelne Anfrage angemeldet; nach der Anfrage vergisst der Wächter sie wieder (unter Octane zusätzlich `FlushAuthenticationState`).
- Am Kiosk gibt es nur freigegebene Routen; Verwaltung, Einstellungen und Löschen sind dort nicht erreichbar, auch mit den Rechten dafür.
- Ein gesperrter Kiosk antwortet auf JSON-Anfragen mit 401, auf Seiten mit dem Sperrbildschirm.

## Code

| Was | Wo |
|---|---|
| Provider, Rechte, Kacheln, Einstellungen | `modules/Kiosk/KioskServiceProvider.php`, `module.json` |
| Routen | `modules/Kiosk/routes/web.php` (Kopplung, Sperrbildschirm, Verwaltung), `routes/kiosk.php` (Seiten des Kerns am Kiosk) |
| Gerät, Plattform | `modules/Kiosk/Models/KioskDevice.php`, `Enums/KioskPlatform.php` |
| Sitzung, Middleware | `modules/Kiosk/Support/KioskSession.php`, `Http/Middleware` |
| Seiten, Layout, Zeitgeber | `modules/Kiosk/resources/js` (`layouts/KioskLayout.vue`, `composables/useKioskTimer.ts`) |
| Oberfläche im Kern | `app/Support/Surface.php`, `app/Support/Extensions/SurfaceRoutes.php`, `resources/js/composables/useSurface.ts`, `resources/js/layouts/SurfaceLayout.vue` |
| Verleih am Kiosk | `modules/Loans/routes/kiosk.php`, `modules/Loans/Http/KioskLoanController.php`, `modules/Loans/resources/js/pages/Kiosk.vue` |
| Demo-Daten | `modules/Kiosk/Support/KioskDemo.php`, siehe [DEMO.md](DEMO.md) |
| Tests | `modules/Kiosk/tests`, `modules/Loans/tests/KioskLoansTest.php`, `tests/Feature/SurfacePathsTest.php` |
