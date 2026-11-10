# Etiketten und Scan

Jeder Gegenstand, jede Box und jeder Standort bekommt ein Etikett mit einem Code. Stocks erzeugt das Etikett aus einer selbst gestalteten Vorlage und gibt es auf einem Niimbot-Etikettendrucker oder einem normalen Drucker aus. Der Code führt über einen Kurzlink zurück: angemeldet auf die Seite in Stocks, sonst auf eine öffentliche Ansicht. Etiketten gehören zum Kern, wie das Inventar.

## Inhalt

- [Kurzlink und Code](#kurzlink-und-code)
- [Öffentliche Ansicht](#öffentliche-ansicht)
- [Vorlagen](#vorlagen)
- [Variablen](#variablen)
- [Editor und Zeichnen](#editor-und-zeichnen)
- [Drucken](#drucken)
- [Drucker und Treiber](#drucker-und-treiber)
- [Etiketten auf Vorrat](#etiketten-auf-vorrat)
- [Scannen](#scannen)
- [Box-Inhaltsliste als PDF](#box-inhaltsliste-als-pdf)
- [Web-App](#web-app)
- [Rechte](#rechte)
- [Code](#code)

## Kurzlink und Code

Der Code auf jedem Etikett ist ein Kurzlink, z. B. `https://stocks.example/s/FSR5-004218` oder `https://stocks.example/s/EU-014-R03.4`. Jede Handykamera und jeder 2D-Handscanner liest ihn.

| Wer öffnet `/s/{code}` | Ergebnis |
|---|---|
| angemeldet mit `inventory.view` | Weiterleitung auf Gegenstand, Box oder Standort |
| angemeldet mit `inventory.edit`, reservierte Nummer | Formular „Gegenstand anlegen“ mit dieser Nummer |
| ohne Anmeldung | [öffentliche Ansicht](#öffentliche-ansicht) |
| unbekannter Code | Hinweis, bei vertippter Nummer mit der Prüfziffer-Meldung; mit `inventory.edit` dazu „Gegenstand anlegen“ |

Ein zurückgebuchter Teil ist ausgemustert; sein altes Etikett öffnet angemeldet die Seite des Teils mit dem Verweis auf den Ursprung.

**Code-Art je Vorlage:** QR-Code (Vorgabe, jede Handykamera öffnet ihn) oder Data Matrix (kleiner, die iPhone-Kamera-App liest ihn nicht; Handscanner und der Scan in Stocks schon). Beide tragen denselben Kurzlink.

**Scan in Stocks:** `App\Support\Labels\ShortLink::codeOf()` macht aus `…/s/FSR5-004218` die Eingabe `FSR5-004218`, egal unter welcher Adresse. `CodeLookup` nutzt das überall (Umlagern, Scannen, Suche), so funktionieren alte Etiketten auch nach einem Umzug der Instanz.

**Adresse auf Etiketten:** Einstellung `labels.base_url` (Verwaltung › Einstellungen › Allgemein, Abschnitt „Etiketten“), leer bedeutet `APP_URL`. Ändert sich die Adresse, öffnet die Handykamera alte Etiketten nicht mehr. Deshalb fragt die Druckvorschau einmal nach, ob die Adresse stimmt (`labels.base_url_confirmed`), und erneut, wenn sie sich ändert.

**Rate Limit:** `throttle:short-links`, ohne Anmeldung 30 Aufrufe je Minute und IP, angemeldet 120 je Minute und Konto (`AppServiceProvider`). Die Seiten tragen `X-Robots-Tag: noindex` und ein `robots`-Meta-Tag.

## Öffentliche Ansicht

`Public/Show` mit `PublicLayout` (Marke oben, kein Menü). Standort, Entleiher und Verlauf bleiben verborgen.

| Gescannt | Sichtbar |
|---|---|
| Einzelstück, Mengenartikel | Name, Nummer, erstes Foto, „Eigentum von {Organisation}“, Kontaktadresse (`legal.contact_email`) als Mail-Link |
| Box mit „Inhalt öffentlich“ (`items.public_contents`) | dazu die Inhaltsliste mit Mengen und Fotos, Boxen in Boxen eingerückt, jede Zeile zum Abhaken |
| Box ohne Freigabe | wie ein Einzelstück |
| Standort | Kennziffer und „Lagerplatz von {Organisation}“ |
| ausgemustert | Name, Nummer, „Nicht mehr im Bestand“ |
| reservierte Nummer | Nummer und „Eigentum von {Organisation}“ |

Abgehakt wird nur im Browser (`localStorage`, Schlüssel `stocks.checklist.<Nummer>`), in Stocks ändert sich nichts. Fotos laufen über signierte Links (`/s-photos/{id}`, eine Stunde gültig), damit niemand Fotos über fortlaufende Ids abruft. „Anmelden“ führt über `/s/{code}?login=1` zur Anmeldung und danach zurück auf die volle Seite.

Die Nummern sind fortlaufend; wer eine errät, sieht Name und Foto. Das Rate Limit bremst das, verhindert es aber nicht (entschieden, siehe Detailplan).

## Vorlagen

Tabelle `label_templates` (`App\Models\LabelTemplate`), verwaltet unter Verwaltung › Einstellungen › Etiketten (`labels.manage`).

| Spalte | Bedeutung |
|---|---|
| `name` | frei |
| `target` | `item`, `box`, `location` oder `reserve` (`App\Enums\LabelTarget`); bestimmt die Variablen |
| `width_mm`, `height_mm` | Größe, frei oder aus den Etikettengrößen der Druckermodelle |
| `elements` | JSON, siehe unten |
| `is_default` | beim Drucken vorausgewählt; je Art genau eine (`makeDefault()`) |

Die Migration legt je Art eine Vorlage in 50 × 30 mm für den B1 an. Vorlagen lassen sich duplizieren und löschen; wird die Standardvorlage gelöscht, wird die älteste andere ihrer Art Standard. Änderungen stehen im Audit.

**Elemente** (`App\Support\Labels\LabelElements`, Typ `LabelElement` in `resources/js/types/labels.d.ts`): Position und Größe in mm ab der oberen linken Ecke, `rotation` 0, 90, 180 oder 270 (gedreht wird um die Mitte des Kastens).

| `type` | Felder | Inhalt |
|---|---|---|
| `text` | `text`, `font_size` (pt), `bold`, `align`, `valign`, `overflow` (`shrink` verkleinert bis 4 pt, `clip` schneidet ab) | Text mit Variablen |
| `code` | `symbology` (`qrcode`, `datamatrix`) | immer der Kurzlink |
| `logo` | – | Druck-Logo der Organisation, ohne eigenes das normale Logo |
| `image` | `path` (`labels/…` auf S3) | freies Bild, hochgeladen im Editor (`POST /labels/images`, PNG/JPG/WebP bis 2 MB, kein SVG) |
| `photo` | – | erstes Foto des Gegenstands; ohne Foto bleibt es weg |
| `line` | `stroke` (mm) | waagerechte Linie durch die Mitte des Kastens; gedreht senkrecht |
| `rect` | `stroke` (mm) | Rahmen entlang des Kastens |

Unbekannte Schlüssel entfernt `LabelElements::clean()` beim Speichern. Bilder werden beim Löschen einer Vorlage nicht gelöscht, weil Kopien dasselbe Bild nutzen können.

## Variablen

Variablen stehen in Texten in geschweiften Klammern. Die Werte liefert der Server (`App\Support\Labels\LabelData`), das Frontend setzt sie nur ein (`fillVariables`). Unbekannte Variablen bleiben stehen.

| Variable | Gegenstand, Box | Standort | Vorrat |
|---|---|---|---|
| `{name}` | Name | Name | – |
| `{number}`, `{code}` | Artikelnummer | Kennziffer | Nummer |
| `{category}` | Kategorie mit Oberkategorie | – | – |
| `{quantity}` | Menge mit Einheit, bei Einzelstücken „1“ | – | – |
| `{home}` | Stammplatz | – | – |
| `{location}` | aktueller Ort | übergeordneter Standort | – |
| `{description}` | Beschreibung | Beschreibung | – |
| `{condition}` | Zustand | – | – |
| `{hint}` | Hinweis fürs Etikett | – | – |
| `{count}` | Zahl der Gegenstände in der Box (nur Box) | – | – |
| `{owner}`, `{contact}`, `{date}`, `{url}` | Organisation, Kontaktadresse, Druckdatum, Kurzlink in Klarschrift | ebenso | ebenso |

**Hinweis fürs Etikett:** Spalte `items.label_hint` (bis 100 Zeichen), im Formular des Gegenstands. Ein Text-Element mit `{hint}` bleibt weg, wenn der Hinweis leer ist. Ein Warnzeichen legt man als freies Bild in die Vorlage. In der Druckvorschau lässt sich der Hinweis nur für diesen Druck ändern.

## Editor und Zeichnen

`resources/js/labels/render.ts` zeichnet ein Etikett auf ein Canvas in jeder Auflösung: im Editor vergrößert, beim Druck in den Punkten des Druckers. So ist die Vorschau genau das Druckbild. Die Schrift ist Inter (mitgeliefert), Codes kommen aus `bwip-js` und werden auf ganze Pixel gerundet, damit sie auf dem Thermodrucker scharf bleiben.

Der Editor (`LabelsEditor`, `resources/js/components/labels/Editor.vue`) dient für Vorlagen (`Labels/Editor`) und für die Druckvorschau (`Labels/Print`):

- Elemente hinzufügen, mit Maus oder Finger verschieben (Raster 0,5 mm), an den Ecken in der Größe ziehen.
- Pfeiltasten rücken um 1 mm, mit Umschalt um 0,1 mm; Entf löscht, Esc hebt die Auswahl auf.
- Rechts die Eigenschaften: Position und Größe als Zahlen, Text mit Variablen-Knöpfen, Schriftgröße, fett, Ausrichtung, „Drehen“, Kopie, Entfernen.
- Ein Beispiel aus dem Inventar füllt die Variablen; genommen wird der Gegenstand, die Box und der Standort mit dem längsten Namen, in einem leeren Inventar erfundene Daten.
- „Vorschau für“ einen Niimbot zeigt das Etikett in Schwarz-Weiß in den Punkten des Druckers und schraffiert den Rand, den der Druckkopf nicht erreicht.

**Warnungen:** Element ragt über den Rand; Element liegt außerhalb der Druckbreite des Niimbot (der B1 druckt 48 mm in der Mitte eines 50 mm breiten Etiketts); Code-Punkte kleiner als 0,4 mm; Text passt nicht und wird abgeschnitten.

**Schwarz-Weiß:** Für Niimbot-Drucker wird hart umgerechnet (`toMonochrome`, Schwelle 50 %). Fotos sind darauf nur grob erkennbar, auf Papier gut.

## Drucken

Gedruckt wird immer über die Vorschau `GET /labels/print` (`labels.print`) mit diesen Parametern, kombinierbar:

| Parameter | Etiketten |
|---|---|
| `items=1,2,3` | Gegenstände und Boxen (Auswahl in der Liste, „Etikett drucken“ am Gegenstand) |
| `box=5` | ganzer Inhalt der Box, Boxen in Boxen eingeschlossen („Etiketten für den Inhalt“) |
| `locations=7` | Standorte („Etikett drucken“ am Standort) |
| `below=7` | alle Lagerplätze und Fächer unter dem Standort |
| `reserved=10,11` | Etiketten auf Vorrat |

Höchstens 500 Etiketten je Druck. Bei mehreren Etiketten wählt man „Alle drucken“ oder „Einzeln“: Dann zeigt eine Liste alle Etiketten zum Ankreuzen, gedruckt (und als gedruckt vermerkt) werden nur die angekreuzten; ein Tipp auf eine Zeile zeigt das Etikett in der Vorschau. Jede Art druckt mit ihrer Vorlage (vorausgewählt die Standardvorlage), Anpassungen gelten für den ganzen Stapel; „Als Vorlage speichern“ übernimmt sie (`labels.manage`). Durch einen Stapel blättert man mit den Pfeilen. Der zuletzt benutzte Drucker liegt im Browser (`localStorage.labelPrinter`), weil ein Bluetooth-Drucker zu einem Gerät gehört.

Nach dem Druck setzt `POST /labels/printed` `items.label_printed_at` bzw. `locations.label_printed_at` (als Abfrage, ohne Audit und ohne `updated_at`). Ein heruntergeladenes Bild zählt als Druck. Die Gegenstandsliste und die Standortliste haben den Filter „Noch ohne Etikett“; die Seite eines Gegenstands zeigt das Datum und bietet „Etikett drucken“ an, solange nie gedruckt wurde. Nach dem Teilen eines Mengenartikels bietet die Erfolgsmeldung „Etikett drucken“ für den neuen Teil an (Flash `label`).

## Drucker und Treiber

Verwaltung › Einstellungen › Drucker (`labels.manage`). Tabelle `printers` mit `driver` (`App\Enums\PrinterDriver`) und `settings` (JSON, je Treiber, Vorgaben in `Printer::DEFAULT_SETTINGS`).

| Treiber | Druckt über | Einstellungen |
|---|---|---|
| `paper` | Druckdialog des Browsers, jeder Drucker | `page`: `single` (ein Etikett je Seite in Etikettengröße, für Etikettendrucker am PC) oder `sheet` (A4 mit `columns`, `rows`, `margin_top`, `margin_left`, `gap_x`, `gap_y` in mm) |
| `niimbot` | direkt per Bluetooth oder USB mit `@mmote/niimbluelib` (nur Chrome und Edge am PC, Mac und Android); sonst PNG für die Niimbot-App | Modell, `density` 1–5, `offset_x`, `offset_y` (mm), `rotation` |

Papier rendert mit 12 Punkten je mm (etwa 300 dpi) in ein unsichtbares iframe mit `@page`. Der Niimbot-Treiber rechnet in den Punkten des Modells (B1: 203 dpi, 50 × 30 mm → 400 × 240 Punkte), dreht und verschiebt nach den Einstellungen und schneidet beim Direktdruck auf die Druckkopfbreite zu (B1: 384 Punkte). Das PNG für die Niimbot-App behält die volle Etikettengröße. Mehrere Bilder kommen als ZIP (`resources/js/labels/zip.ts`, ohne Paket). Ein Stapel druckt nacheinander mit Fortschritt und „Abbrechen“ (wirkt vor dem nächsten Etikett).

**Druckermodelle** (`printer_models`) sind eine eigene Liste, damit neue Niimbot-Modelle ohne Programmieren dazukommen: Name, Auflösung, Druckbreite, Drucktyp von NiimBlueLib (`B1`, `D110`, `B21V1`, …) und Etikettengrößen. Ein Modell, das Drucker nutzen, lässt sich nicht löschen. Mitgeliefert: B1 mit 50 × 30 mm, dazu die Drucker „Niimbot B1“ und „Normaler Drucker“.

**Neuer Treiber** (z. B. Brother, Zebra): Fall in `PrinterDriver`, Vorgaben in `Printer::DEFAULT_SETTINGS`, Prüfregeln in `PrinterController::validated()`, Felder in `Labels/Printers.vue` und eine Ausgabe in `resources/js/labels/drivers/`.

NiimBlueLib ist inoffiziell und im Alpha-Stand. Der Direktdruck muss mit dem echten B1 getestet werden; der Bild-Export ist der sichere Weg.

## Etiketten auf Vorrat

Verwaltung › Einstellungen › Etiketten auf Vorrat (`labels.print`): Anzahl wählen (höchstens 200), Stocks reserviert so viele Nummern (`App\Actions\Labels\ReserveNumbers`, Tabelle `reserved_numbers`) und öffnet die Vorschau mit der Vorlage „Vorrat“. Die Nummern kommen aus demselben Zähler wie alle Artikelnummern.

Wer eine reservierte Nummer scannt und `inventory.edit` hat, landet in „Gegenstand anlegen“ mit genau dieser Nummer (`/inventory/items/create?number=…`, Feld `reserved_number`). `CreateItem` sperrt die Reservierung in der Transaktion und markiert sie als benutzt (`used_at`), eine Nummer geht also nur einmal. Offene Reservierungen lassen sich erneut drucken oder freigeben (`released_at`); freigegebene Nummern werden nie neu vergeben, damit kein altes Etikett auf einen fremden Gegenstand zeigt.

## Scannen

**Kamera:** `InventoryCameraScanner` mit dem Paket `barcode-detector` (ZXing als WebAssembly, liest QR-Code und Data Matrix, auch auf dem iPhone). Die `.wasm`-Datei gehört zum Build; ohne die Einstellung in `createDetector()` lüde das Paket sie von jsDelivr. Taschenlampe und Kamerawahl, wo das Gerät es kann. Derselbe Code löst erst nach 2,5 Sekunden Pause wieder aus; jeder Treffer piept und vibriert. Die Kamera braucht HTTPS (lokal geht `localhost`); verweigert der Browser sie, erklärt die Komponente, wie man sie freigibt.

**Handscanner:** wie bisher über Eingabefelder. Auf `/inventory/scan` nimmt `useScannerInput` den Scanner auch ohne angeklicktes Feld an (Tasten bis Enter, eine Pause über eine Sekunde beginnt neu).

**Seite „Scannen“** (`/inventory/scan`, `inventory.view`): Kamera, darunter am Handy eine Karte, die sich über die Kamera schiebt; am PC daneben. Die Daten kommen aus `GET /inventory/lookup`.

| Gescannt | Aktionen (je nach Recht) |
|---|---|
| Gegenstand | Umlagern (der nächste Scan ist das Ziel), Verbraucht, Zustand, Zurückbuchen bei Teilen, Etikett, Öffnen |
| Box | dasselbe, „Inhalt“ öffnet die Seite der Box |
| Standort | Hierher umlagern (jeder folgende Gegenstand wird dorthin gebucht, bis man beendet), Etikett, Öffnen |
| reservierte Nummer | Gegenstand anlegen |

Die Dialoge für Verbraucht und Zustand sind dieselben Komponenten wie auf der Seite des Gegenstands (`InventoryConsumeModal`, `InventoryConditionModal`). Zurückbuchen läuft als Umlagern auf den Ursprung (`MoveController`), damit die Seite offen bleibt. Die Kamera startet beim nächsten Besuch von selbst, wenn sie zuletzt an war (`localStorage.scanCamera`).

Die Seite „Umlagern“ hat einen Kamera-Knopf; der erste Scan wählt den Gegenstand, der zweite das Ziel.

Untere Leiste am Handy: Dashboard, Gegenstände, Scannen, Mehr („Umlagern“ liegt unter „Mehr“). Am PC steht „Scannen“ als Knopf oben in der Seitenleiste.

## Box-Inhaltsliste als PDF

„Inhaltsliste (PDF)“ auf der Seite einer Box öffnet einen Dialog; mit „Vorschaubilder mitdrucken“ kommt das Vorschaubild der Box über die Tabelle und das jedes Gegenstands klein in seine Zeile (`GET /inventory/items/{id}/contents.pdf?photos=1`, `inventory.view`). Erzeugt wird sie mit dompdf (`resources/views/pdf/box-contents.blade.php`).

Oben stehen Druck-Logo, Name der Organisation, Box, Datum, Anzahl und der QR-Code des Kurzlinks mit „Scannen: Inhaltsliste digital öffnen und abhaken“.

Mit **„Als Brief (DIN 5008)“** (`letter=1`) wird die erste Seite ein Geschäftsbrief nach DIN 5008, Form B, passend für einen Fensterumschlag DIN lang. Im Dialog gibt man dazu die Anschrift des Empfängers ein (`recipient`, Zeilen durch Zeilenumbrüche getrennt); bleibt sie leer, steht dort die eigene Anschrift.

- Briefkopf oben links: Druck-Logo, Name der Organisation und Kontaktadresse. Die Anschrift (`organization.address`) steht nur einmal, im Anschriftfeld.
- Anschriftfeld (20 mm von links, 45 mm von oben, 85 × 45 mm): Rücksendezeile mit der Anschrift der Organisation, leere Vermerkzone und die Anschrift des Empfängers. Der Text im Anschriftfeld beginnt wie der Rest der Seite bei 25 mm. Die Rücksendezeile wird gemessen: Passt sie nicht in 80 mm, wird die Schrift kleiner (bis 5 pt), dann bleiben nur Name, Straße und Ort. Die Anschrift bekommt eine Schriftgröße, bei der keine Zeile umbricht (10 bis 6 pt).
- Informationsblock ab 125 mm: Name und Nummer der Box, Datum, Anzahl, QR-Code des Kurzlinks mit „Scannen: Inhaltsliste digital öffnen und abhaken“.
- Faltmarken bei 105 und 210 mm, Lochmarke bei 148,5 mm am linken Rand.

Darunter die Tabelle: Abhakkästchen, optional Foto, Name mit Kennzeichen „Box“ und „Verbrauchsmaterial“, Beschreibung und Notiz, Nummer, Menge; Boxen in Boxen eingerückt. Jede Seite hat unten Organisation, Kontakt und die Nummer der Box. Bilder werden mit GD in PNG bzw. JPEG umgerechnet, weil dompdf nicht jedes Format liest (z. B. WebP). Die öffentliche Ansicht bekommt den Knopf nicht.

## Web-App

`/manifest.webmanifest` und `/web-app/icon-{180|192|512}.png` (auch für Gäste, `WebAppController`). Die Symbole entstehen mit GD aus dem Logo (Logo zu 80 % auf Weiß), ohne Logo eine Box auf der Akzentfarbe; sie liegen eine Woche im Cache. Auf dem Handy lässt sich Stocks zum Startbildschirm hinzufügen und läuft im Vollbild (`display: fullscreen`). Einen Betrieb ohne Netz gibt es nicht.

## Rechte

| Recht | Erlaubt | FSR-Mitglied | Vorstand |
|---|---|---|---|
| `labels.print` | Etiketten drucken, Sammeldruck, Etiketten auf Vorrat | ja | ja |
| `labels.manage` | Vorlagen, Bilder, Drucker und Druckermodelle verwalten; „Als Vorlage speichern“ | – | – (nur Admin) |

Scannen braucht `inventory.view`, die Aktionen die Rechte des Inventars. Einen Gegenstand aus einer reservierten Nummer anlegen darf, wer `inventory.edit` hat. Bestehende Instanzen: Admin bekommt die Rechte mit `stocks:sync`, den übrigen Rollen gibt man sie in der Rollenverwaltung.

## Code

| Ort | Inhalt |
|---|---|
| `app/Models` | `LabelTemplate`, `Printer`, `PrinterModel`, `ReservedNumber` |
| `app/Enums` | `LabelTarget` (mit Variablen je Art), `PrinterDriver` |
| `app/Support/Labels` | `ShortLink`, `LabelData` (Werte der Variablen), `LabelElements` (Prüfregeln) |
| `app/Actions/Labels/ReserveNumbers.php` | Nummern reservieren |
| `app/Http/Controllers/Labels` | Vorlagen, Drucker, Modelle, Vorschau, Vorrat |
| `app/Http/Controllers/ShortLinkController.php` | Kurzlink und öffentliche Ansicht |
| `app/Http/Controllers/Inventory/ScanController.php`, `ContentsPdfController.php` | Scan-Seite, Box-PDF |
| `app/Http/Controllers/WebAppController.php` | Manifest und Symbole |
| `resources/js/labels` | `render.ts` (Zeichnen), `drivers/` (Papier, Niimbot, Bild), `zip.ts` |
| `resources/js/components/labels` | Editor, Inhaltsliste der öffentlichen Ansicht |
| `resources/js/pages/Labels`, `resources/js/pages/Public` | Seiten |
| `database/migrations/2026_11_11_000000_create_label_tables.php` | Tabellen, Spalten und mitgelieferte Daten |
| `tests/Feature/Labels` | Tests |
