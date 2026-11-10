# Inventar

Was besitzen wir, wo liegt es, wie geht es ihm. Das Inventar gehört zum Kern (`app/`), nicht zu einem Modul, weil Verleih, Kiosk und öffentliche Seite darauf aufbauen.

## Inhalt

- [Begriffe](#begriffe)
- [Standorte und Kennziffern](#standorte-und-kennziffern)
- [Gegenstände, Boxen, Mengenartikel](#gegenstände-boxen-mengenartikel)
- [Artikelnummern](#artikelnummern)
- [Umlagern und Bewegungsprotokoll](#umlagern-und-bewegungsprotokoll)
- [Zustand](#zustand)
- [Ausmustern und Löschen](#ausmustern-und-löschen)
- [Fotos auf S3](#fotos-auf-s3)
- [Rechte](#rechte)
- [Code](#code)

## Begriffe

| Begriff | Bedeutung |
|---|---|
| Standort | Adresse, Raum, Lagerplatz oder Fach |
| Kennziffer | Pfad eines Standorts aus den Kürzeln, z. B. `EU-014-R03.4` |
| Gegenstand | alles Inventarisierte, auch Boxen |
| Box | Gegenstand mit „ist Behälter“, kann andere Gegenstände enthalten |
| Mengenartikel | Gegenstand mit Stückzahl und Einheit (Kabel, Becher, Klebeband) |
| Einheit | Stück, Meter, Rollen …; vorgegebene Liste in den Einstellungen |
| Ort | wo ein Gegenstand gerade liegt: ein Standort oder eine Box |
| Stammplatz | wohin ein Gegenstand gehört; optional |

## Standorte und Kennziffern

Vier Ebenen, jede liegt in der davor: Adresse → Raum → Lagerplatz → Fach. Die Ebene ergibt sich aus dem übergeordneten Standort.

- Kürzel bestehen nur aus Buchstaben und Ziffern und werden groß geschrieben.
- Die Kennziffer verbindet die Kürzel mit `-`, das Fach mit `.`: `EU` · `EU-014` · `EU-014-R03` · `EU-014-R03.4`.
- Die Kennziffer ist gespeichert (`locations.full_code`, eindeutig) und wird bei jeder Umbenennung oder Verschiebung für alle Standorte darunter neu berechnet (`SaveLocation`). Eindeutige Kennziffern bedeuten: Ein Kürzel gibt es unter demselben Standort nur einmal.
- Ein Standort lässt sich mit allem darunter löschen, wenn dort nichts liegt und nichts seinen Stammplatz hat (`DeleteLocation`).
- Gegenstände liegen in einem Raum, auf einem Lagerplatz oder in einem Fach, z. B. ein Beamer direkt im Raum, aber nie direkt an einer Adresse (`Placement::check`).

## Gegenstände, Boxen, Mengenartikel

Ort und Stammplatz zeigen jeweils auf einen Standort **oder** eine Box (`location_id`/`container_id`, `home_location_id`/`home_container_id`), nie auf beides.

**Dynamische Lagerung.** Maßgeblich ist der aktuelle Ort. Weil Umlagern nur zwei Scans kostet, darf alles dort liegen, wo gerade Platz ist. Ein Stammplatz ist nur für Dinge gedacht, die immer an denselben Platz zurück sollen; liegt so ein Gegenstand woanders, zeigt Stocks das an und bietet „Zurück an den Stammplatz“.

**Boxen.**
- Der Inhalt zeigt auf die Box. Wird die Box umgelagert, ändert sich am Inhalt nichts, er zieht einfach mit. `Item::effectiveLocation()` liefert den Standort der äußersten Box.
- Eine Box kann nicht in sich selbst oder in eine Box aus ihrem eigenen Inhalt (`Placement::check`).
- Nur Gegenstände mit „ist Behälter“ nehmen etwas auf; das Merkmal lässt sich nicht entfernen, solange etwas darin liegt.
- „Inhalt öffentlich zeigen“ (Standard: an) nutzt erst die öffentliche Box-Ansicht per QR-Code.

**Mengenartikel.** Wird nur ein Teil umgelagert, teilt Stocks den Gegenstand: Der Teil wird ein neuer Gegenstand mit eigener Nummer, gleichem Namen, gleicher Kategorie, Kopien der Fotos und Verweis auf den Ursprung (`split_from_id`). So kann jeder Teil sein eigenes Etikett tragen. Eine geänderte Menge beim Bearbeiten wird als Zählung protokolliert.

**Zurückbuchen.** Ein abgeteilter Teil lässt sich zu seinem Ursprung zurückbuchen (`MergeItem`), ganz oder teilweise: auf seiner Seite über „Zurückbuchen“ oder auf der Seite „Umlagern“, indem man als Ziel die Nummer des Ursprungs scannt. Die Menge kommt beim Ursprung dazu, beide bekommen eine Bewegung `merge`. Ein ganz zurückgebuchter Teil wird mit Grund „zurückgebucht“ ausgemustert und behält seine Nummer und seinen Verlauf. Recht: `inventory.move`.

**„Ohne Platz“** listet Gegenstände ohne Ort und solche mit Stammplatz, die nicht dort liegen (`Item::misplaced()`).

## Artikelnummern

`FSR5-004218`: Präfix der Instanz, laufende Zahl (mindestens fünf Stellen, `00421`) und Prüfziffer (`8`).

- **Präfix:** Einstellung „Präfix der Artikelnummern“ (1–6 Großbuchstaben oder Ziffern). Leer bedeutet: Kurzname der Organisation ohne Leer- und Sonderzeichen.
- **Laufende Zahl:** je Präfix in `number_sequences`; die Zeile ist beim Vergeben gesperrt, so bekommen zwei gleichzeitig angelegte Gegenstände nie dieselbe Nummer. Nach 99999 wird sie sechsstellig.
- **Prüfziffer:** Luhn-Verfahren. Eine vertippte Ziffer oder zwei vertauschte fallen auf; die Suche meldet dann „Die Prüfziffer stimmt nicht“.
- **Fest:** Eine Nummer ändert sich nie, auch nicht bei neuem Präfix.
- **Eingabe:** mit oder ohne Bindestrich, groß oder klein (`fsr5004218`).

## Umlagern und Bewegungsprotokoll

Die Seite **Umlagern** arbeitet in zwei Schritten: erst den Gegenstand oder die Box scannen, dann das Ziel (Kennziffer oder Boxnummer). Danach wird sofort gebucht, und die Seite wartet auf den nächsten Scan. Ein Barcode-Handscanner tippt den Code wie eine Tastatur. Bei Mengenartikeln fragt die Seite die Menge ab, vorausgefüllt mit allem. Auf der Seite eines Gegenstands gibt es dasselbe als Dialog mit Vorschlägen.

Jede Änderung des Orts schreibt eine Zeile in `item_movements`:

| Vorgang | Action | Art |
|---|---|---|
| Anlegen | `CreateItem` | `create`, von nirgends an den Ort |
| Umlagern, auch zurück an den Stammplatz | `MoveItem` | `move` |
| Teilmenge umlagern | `MoveItem` mit Menge | `split`, je eine Zeile für Ursprung und neuen Teil |
| Menge ändern | `UpdateItem` | `count`, Notiz „Menge alt → neu“ |
| Ausmustern | `RetireItem` | `retire`, vom Ort nach nirgends, Notiz „Grund: Notiz“ |
| Zurückbuchen eines Teils | `MergeItem` | `merge` bei Teil und Ursprung |
| Verbraucht | `RetireItem` mit Grund verbraucht und Menge | `consume` mit Menge; der Gegenstand bleibt am Ort |
| Teilmenge ausmustern | `RetireItem` mit Menge | `retire` mit Menge; der Gegenstand bleibt am Ort |

Wird eine Box bewegt, bekommt nur die Box eine Zeile. Zeilen werden nie geändert; ein falscher Ort wird durch eine neue Bewegung korrigiert. Feldänderungen an Gegenständen, Standorten und Kategorien stehen zusätzlich im Änderungsprotokoll.

**Einträge löschen.** Mit dem Recht `inventory.history.delete` (Vorgabe: nur Admin) lassen sich Bewegungen und Zustandswechsel aus dem Verlauf löschen, z. B. eine Fehlmeldung oder ein Zustandswechsel, dessen Fotos nie auf S3 ankamen. Fotos eines Zustandswechsels werden mit gelöscht; fehlt die Datei auf S3, wird nur der Eintrag entfernt. Ort und Zustand des Gegenstands ändern sich dabei nicht (`ItemHistoryController`). Das Änderungsprotokoll hält beim Gegenstand fest, wer welchen Eintrag gelöscht hat und was darin stand („Verlaufseintrag gelöscht“). Zustandswechsel stehen dort mit Namen („Zustand geändert“), gelöschte Zustände als „Gelöscht“.

## Zustand

Die Zustände verwalten Admins unter **Verwaltung › Einstellungen › Zustände** (Recht `inventory.options.manage`, Tabelle `item_conditions`). Vorgabe: neu, gut, gebraucht, defekt. Ein Zustand kann „bedeutet einen Schaden“ sein; er wird dann rot hervorgehoben und ist später für den Verleih gesperrt. Gelöscht wird ein Zustand nur, wenn kein Gegenstand ihn hat, und einer bleibt immer.

Der Zustand eines Gegenstands ändert sich nur über „Zustand ändern“ (`ChangeItemCondition`), nicht beim Bearbeiten:

- Eine Begründung ist Pflicht, Fotos sind möglich und empfohlen.
- Jeder Wechsel steht mit altem und neuem Zustand, Begründung, Fotos, Person und Zeit in `item_condition_changes` und im Verlauf des Gegenstands. Die Zustände sind dort als Namen gespeichert, so bleibt der Verlauf lesbar, wenn ein Zustand umbenannt oder gelöscht wird.
- `loan_id` verknüpft später einen Wechsel mit einem Verleih (Verleih-Modul, Phase 3).
- Zustandsfotos sind `item_photos` mit `condition_change_id` und erscheinen nicht in der Galerie.

## Einheiten

Mengenartikel wählen ihre Einheit aus einer Liste, die Admins unter **Verwaltung › Einstellungen › Einheiten** pflegen (Recht `inventory.options.manage`, Tabelle `item_units`). Vorgabe: Stück, Meter, Rollen, Packungen, Sets; Rollen und Packungen sind als **Verbrauchsmaterial** markiert. Gegenstände speichern den Namen (`items.unit`), damit Verlauf und Meldungen lesbar bleiben; Umbenennen ändert ihn auch bei den Gegenständen. Löschen geht nur, wenn kein Gegenstand die Einheit hat.

## Ausmustern und Löschen

**Ausmustern** (`RetireItem`) statt löschen: Datum, Grund (verbraucht, defekt, verloren, verkauft, entsorgt, sonstiges) und Notiz; der Gegenstand verliert seinen Ort, behält Nummer, Fotos und Verlauf und ist in Listen ausgeblendet (Filter „Ausgemusterte zeigen“). Eine Box muss dafür leer sein.

**Verbraucht.** Mengenartikel, deren Einheit als Verbrauchsmaterial markiert ist, haben auf ihrer Seite einen eigenen Knopf „Verbraucht“: Menge und optional wofür, fertig. Die Menge wird abgezogen und als Bewegung `consume` („Verbraucht“) im Verlauf vermerkt; bei der ganzen Menge wird der Gegenstand ausgemustert. Recht: `inventory.move`, damit es jedes Mitglied schnell erledigen kann (`POST /inventory/items/{id}/consume`). Andere Mengenartikel haben den Knopf nicht.

**Teilmenge ausmustern.** Bei Mengenartikeln fragt „Ausmustern“ eine Menge ab (Vorgabe: alles), z. B. zwei defekte Kabel. Weniger als alles zieht die Menge ab und vermerkt sie mit Grund im Verlauf; der Gegenstand bleibt mit dem Rest an seinem Ort. „verbraucht“ steht dort nicht zur Wahl, das hat den eigenen Knopf. Recht: `inventory.retire`.

**Löschen** (`DeleteItem`) nur bei Fehleingaben: keine Bewegung außer dem Anlegen, keine Zustandswechsel, nicht als Box, Stammplatz oder Ursprung einer Teilung genutzt.

## Fotos auf S3

Fotos liegen immer auf der Disk `s3` unter `items/<id>/<zufälliger Name>`; der Bucket bleibt privat.

- Fotos kommen schon beim Anlegen dazu oder später auf der Seite des Gegenstands. Bis zu fünf Fotos je Gegenstand, JPG, PNG oder WebP, höchstens 5 MB. SVG ist verboten, weil es Skripte enthalten kann.
- Der Browser verkleinert Fotos vorher auf 1600 Pixel (`useImageCompression`, aus Portals).
- Stocks liefert Fotos über `/inventory/photos/{id}` mit Rechteprüfung und einem Tag Browser-Cache aus. Der S3-Server muss für Besucher nicht erreichbar sein.
- Das erste Foto ist das Vorschaubild; „Als erstes“ ändert die Reihenfolge. Löschen entfernt auch die Datei.

**MinIO:** `docker-compose.yaml` und `docker-compose.sail.yaml` starten MinIO wie in Portals (`ghcr.io/coollabsio/minio`, S3-API auf Port 9000, Konsole auf http://localhost:9001). Der Dienst `minio-setup` wartet, bis MinIO bereit ist, legt den Bucket aus `AWS_BUCKET` an und beendet sich; Exit-Status 0 ist richtig. Zugang standardmäßig `minioadmin`/`minioadmin123`, im Betrieb über die `.env` ändern.

**Anderer S3-Speicher:** `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT` setzen (siehe [SETUP.md](SETUP.md)); die MinIO-Dienste kann man dann weglassen.

## Rechte

| Recht | Erlaubt | Freies Mitglied | FSR-Mitglied | Vorstand |
|---|---|---|---|---|
| `inventory.view` | Inventar ansehen | ja | ja | ja |
| `inventory.move` | Umlagern, Zustand ändern, Verbrauchsmaterial ausbuchen | ja | ja | ja |
| `inventory.edit` | Anlegen, Bearbeiten, Fotos | – | ja | ja |
| `locations.manage` | Standorte und Kategorien | – | ja | ja |
| `inventory.options.manage` | Zustände und Einheiten verwalten | – | – | – (nur Admin) |
| `inventory.history.delete` | Einträge aus dem Verlauf löschen | – | – | – (nur Admin) |
| `inventory.retire` | Ausmustern (auch Teilmengen), Fehleingaben löschen | – | – | ja |

Die Spalten sind die Vorgabe der Startrollen in `config/stocks.php`; danach ändert man sie in der Rollenverwaltung.

## Code

| Ort | Inhalt |
|---|---|
| `app/Models` | `Location`, `Category`, `Item`, `ItemCondition`, `ItemPhoto`, `ItemMovement`, `ItemConditionChange` |
| `app/Enums` | `LocationType`, `ItemKind`, `RetireReason`, `MovementType` mit deutschen Bezeichnungen |
| `app/Actions/Inventory` | ein Vorgang je Klasse, siehe Tabellen oben |
| `app/Support/Inventory` | `ItemNumbers` (Nummern, Prüfziffer), `CodeLookup` (Code → Gegenstand oder Standort), `Placement` (Regeln für Boxen) |
| `app/Support/Table/ItemsTable.php` | Liste mit Suche, Filtern und Live-Update |
| `app/Http/Controllers/Inventory` | Seiten und JSON-Endpunkte (`lookup`, `places`, `stats`) |
| `resources/js/pages/Inventory` | Seiten; Bausteine in `resources/js/components/inventory` |
| `tests/Feature/Inventory` | Tests je Regel |
| `database/seeders/InventorySeeder.php` | Demo-Inventar |
