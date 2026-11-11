# Entscheidungsprotokoll

Warum Stocks so gebaut ist, wie es gebaut ist. Neue Einträge oben; jeder Eintrag mit Datum, Entscheidung, Grund und verworfener Alternative.

## 2026-11-11 · Rückmeldungen zu Phase 3

**Mailserver in den Einstellungen statt in der `.env`.** Wer Stocks betreibt, soll den Mailserver ohne Zugang zum Server ändern können. Das Passwort liegt mit `APP_KEY` verschlüsselt in `settings`, die Umgebung bleibt als Rückfall. Der Status im Menü kommt aus dem Cache (5 Minuten), damit keine Seite auf den Mailserver wartet. Verworfen: nur `.env` (Wunsch von Maurice), Status bei jedem Seitenaufruf prüfen (zu langsam).

**Vermisst statt sofort verloren.** Was bei der Rückgabe fehlt, bleibt im Bestand, aber ohne Ort und gesperrt für Buchungen, bis es wieder auftaucht („Wieder da“) oder beim Abschließen der Mängel als verloren ausgemustert wird. Grund: Meist taucht etwas ein paar Tage später auf; eine abgeschlossene Buchung soll aber keinen Bestand im Nirgendwo zurücklassen. Verworfen: sofort ausmustern (dann ist ein Fund ein neuer Gegenstand) und „Mängel abschließen“ als reine Notiz (Bestand bleibt falsch).

**Eine Box hakt ihren Inhalt mit ab.** Bei Ausgabe und Rückgabe hakt eine abgehakte Box ihren Inhalt ab, der letzte Inhalt die Box; „Alles vollständig“ und `complete-box` entfallen. Ausnahmen: eine ungeprüft zurückgekommene Box und die spätere Prüfung, dort zählt jede Zeile einzeln.

## 2026-11-11 · Phase 3

**Inhalt einer Box als Zeilen statt als JSON.** Bei der Ausgabe wird der Inhalt jeder Box zu eigenen Zeilen in `loan_items` mit `parent_id`. So lässt sich jeder Gegenstand der Box bei Ausgabe, Rückgabe und Prüfung einzeln abhaken, mit Mangel vermerken und sperren, mit denselben Spalten wie eine Position. Verworfen: `loan_items.contents` als JSON (stand im Detailplan).

**PDFs entstehen beim Herunterladen aus dem eingefrorenen Inhalt.** Gespeichert werden je Dokument der Inhalt (`snapshot`) und seine SHA-256-Prüfsumme; das PDF rendert dompdf daraus bei jedem Download. Grund: Das PDF zeigt so immer genau das Unterschriebene, es gibt keine zweite Kopie, die abweichen kann, und Ausgabe und Rückgabe hängen nicht an S3. Verworfen: PDFs bei der Ausgabe auf S3 ablegen (Detailplan). Nur das Foto eines auf Papier unterschriebenen Blatts liegt auf S3.

**Unterschriften als Bild in der Datenbank.** Ein PNG der Unterschrift hat wenige Kilobyte und liegt als Data-URI in `loan_signatures.image`. Verworfen: `image_path` auf S3 (Detailplan), aus demselben Grund wie oben.

**„Verschmutzt“ als Verlaufseintrag ohne neuen Zustand.** Fotos hängen nach dem Detailplan an einer Zustandsänderung. Für „verschmutzt“ schreibt die Rückgabe einen Eintrag mit gleichem altem und neuem Zustand, damit Notiz und Fotos im Verlauf des Gegenstands stehen. Verworfen: eigene Tabelle für Fotos von Mängeln.

**Ausgabe über `MoveItem::takeAway()`.** Der Kern bekommt eine Methode, die einen Gegenstand ohne neuen Ort wegnimmt und bei Mengen abteilt, mit der Bewegungsart des Aufrufers (`issue`). Grund: Teilen bleibt an einer Stelle. Verworfen: eigene Mengenlogik im Modul.

**„Noch nicht eingeräumt“ wird abgeleitet.** Eine zurückgebuchte Position ohne `stowed_at`, deren Gegenstand keinen Ort hat (oder deren abgeteilter Teil noch besteht), wartet aufs Einräumen. Wer den Gegenstand auf anderem Weg an einen Ort bringt, hat ihn damit eingeräumt. Verworfen: ein Listener im Kern, der `stowed_at` setzt.

**Zeitzone `Europe/Berlin` als Vorgabe.** Abholung und Rückgabe werden als Ortszeit eingegeben und gedruckt; `config/app.php` liest `APP_TIMEZONE` (Vorgabe `Europe/Berlin`). Vorher galt UTC aus dem Framework. Noch keine Instanz läuft im Betrieb, gespeicherte Zeiten müssen nicht umgerechnet werden.

**Start-Rechte von Modulen in der `module.json`.** `start_roles` gibt den Startrollen bei der Einrichtung Rechte des Moduls. Grund: Ein Modul bleibt in seinem Ordner, `config/stocks.php` kennt keine Modulrechte. Verworfen: die Rechte des Verleihs in `config/stocks.php`.

**Ids in Routen des Moduls nur als Zahlen.** `Route::patterns` für `loan`, `position`, `row` usw., damit `/loans/settings` nicht als Buchung „settings“ gelesen wird.

**Basisklasse der Modultests per `autoload-dev`.** Der Ordner heißt wie beim Beispielmodul `tests`; nach `"Modules\\": "modules/"` müsste der Namensraum `Modules\Loans\Tests` aber im Ordner `Tests` liegen, was unter Linux nicht passt. Ein Eintrag in `composer.json` macht `LoansTestCase` ladbar. Verworfen: `require_once` in jedem Test.

**Demo-Daten mit verstellter Uhr.** Der Generator lässt jede Buchung zu ihrer Zeit durch die Actions laufen (`Carbon::setTestNow`), damit Dokumente und Verlauf echte Daten tragen. Mengenartikel teilen sich mehrere Buchungen; reicht die Menge nicht, bleibt eine Buchung als Entwurf mit Kollision stehen (ein Pflichtfall ohnehin). Der Generator leert vorher den Cache, weil nach `migrate:fresh` Einstellungen und Rechte im Cache veraltet sind.

**Pack-PIN nach richtiger Eingabe in der Sitzung.** Die öffentliche Seite merkt sich die PIN je Box in der Sitzung des Browsers, um Buchung und Kontrollen zu zeigen und die Kontrolle zu speichern. Fehlversuche werden ohne Transaktion gezählt, sonst rollt die Ablehnung den Zähler zurück.

**Kontakte im Formular per JSON anlegen.** Der Dialog „Neu“ im Kontaktfeld schickt JSON an `/loans/contacts` und wählt den neuen Kontakt sofort, ohne die Seite neu zu laden. Sonst nutzt das Modul Inertia-Besuche.

## 2026-11-11 · Phase 2

**Etiketten im Kern.** Wie im Gesamtplan („Kern inklusive Etiketten und Scan“). Verworfen: eigenes Modul.

**Gezeichnet wird auf Canvas statt als SVG.** Eine Funktion (`resources/js/labels/render.ts`) zeichnet Vorschau, Druckbild und Bild-Export in der jeweiligen Auflösung; der Editor legt nur die Griffe darüber. Grund: Ein SVG lädt als Bild weder die mitgelieferte Schrift noch Bilder, die Umrechnung in Schwarz-Weiß braucht ohnehin Pixel, und so ist die Vorschau genau das Druckbild. Verworfen: SVG im Editor und eine zweite Umrechnung für den Druck (stand im Detailplan).

**Warnung zur Druckbreite nur für Elemente außerhalb.** Der B1 druckt 48 mm in der Mitte eines 50 mm breiten Etiketts. Eine Warnung bei jedem breiteren Etikett hätte schon die mitgelieferten Vorlagen ständig angemahnt; gewarnt wird, wenn ein Element in den Rand reicht, und der Editor schraffiert den Rand. Verworfen: Warnung allein nach der Etikettenbreite (Detailplan).

**PNG für die Niimbot-App in voller Etikettengröße, Direktdruck auf Druckkopfbreite zugeschnitten.** Die App erwartet das ganze Etikett (400 × 240 Punkte), NiimBlueLib die Breite des Druckkopfs (384 Punkte).

**Kamera-Scan mit `barcode-detector`, WebAssembly im Build.** Das Paket lädt ZXing sonst von jsDelivr; mit der eigenen Datei gehen keine Daten an Dritte (wie bei der Schrift). Verworfen: `qr-scanner` (liest kein Data Matrix).

**ZIP ohne Paket.** Mehrere PNG werden unkomprimiert gepackt (`resources/js/labels/zip.ts`, rund 60 Zeilen); PNG sind schon komprimiert. Verworfen: JSZip.

**Fotos der öffentlichen Ansicht über signierte Links.** Eine Stunde gültig, damit niemand Fotos über fortlaufende Ids abruft. Verworfen: Fotos der öffentlichen Ansicht ohne Prüfung ausliefern.

**Zeitpunkt des letzten Drucks ohne Audit.** Gespeichert als Abfrage, damit weder das Änderungsprotokoll noch `updated_at` sich ändern (Detailplan).

**Neue Rechte über die Startrollen, Admin über `stocks:sync`.** Wie in Phase 1: `labels.print` für FSR-Mitglied und Vorstand, `labels.manage` nur Admin. Bestehende Entwicklungsdatenbanken: `stocks:sync` gibt Admin die Rechte, den anderen Rollen vergibt man sie in der Rollenverwaltung.

## 2026-11-10 · Phase 1

**Inventar im Kern statt als Modul.** Der Gesamtplan zählt das Inventar zum Kern; Verleih, Kiosk und öffentliche Seite bauen darauf auf, ein Abschalten ergibt keinen Sinn. Der Detailplan nannte an zwei Stellen noch `modules/Inventory`. Verworfen: eigenes Modul.

**Rechte für das Inventar über die Startrollen.** Die neuen Rechte stehen in `config/stocks.php` und in den Startrollen. Grund: Es gibt noch keine Instanz im Betrieb; eine lokale Entwicklungsdatenbank wird mit `migrate:fresh --seed` neu aufgebaut. Verworfen: eine Zuordnung beim ersten Einschalten (stand im Detailplan für ein Modul). Bestehende Instanzen müssten die Rechte in der Rollenverwaltung vergeben; Admin hat sie automatisch.

**Kein Check-Constraint für „Standort oder Box“.** Die Actions setzen Ort und Stammplatz nur über `Item::setPlace()`/`setHome()`, die immer einen der beiden Werte leeren. Grund: Laravel legt Check-Constraints nicht an, und SQLite (Tests) kann sie nachträglich nicht hinzufügen. Verworfen: rohes SQL je Datenbank.

**Kategorien direkt im Controller.** Wie bei Kiosk-Geräten: Anlegen, Ändern, Löschen mit je einer Regel, keine Actions.

**Ein Endpunkt fürs Umlagern.** Scan-Seite und Dialog auf der Detailseite schicken beide Gegenstand und Ziel-Code an `POST /inventory/move`. Der Server löst den Code auf (`CodeLookup`), so funktionieren Handscanner, Tippen und Vorschläge gleich.

**Mengenartikel als ganze Zahlen.** Meter oder Rollen werden gezählt, nicht gemessen. Verworfen: Dezimalzahlen, die beim Teilen Rundungsfragen aufwerfen.

**Präfix nur in den Einstellungen.** Der Einrichtungsassistent fragt das Präfix nicht ab; leer gilt der Kurzname, den der Assistent schon abfragt. Grund: ein Feld weniger beim ersten Start.

**MinIO wie in Portals.** `docker-compose.yaml` und `docker-compose.sail.yaml` starten MinIO (`ghcr.io/coollabsio/minio`) und legen den Bucket mit `minio-setup` an, übernommen aus dem Portals-Zweig `feat/missing-s3-service`. Abweichung: kein `container_name`, damit mehrere Instanzen auf einem Server laufen können.

**Zustände als Tabelle, verwaltet von Admins.** Statt fester Werte im Code stehen die Zustände in `item_conditions`; nur Admin hat `inventory.options.manage`. Der Verlauf speichert Namen statt Verweise, damit Umbenennen und Löschen ihn nicht verändern. Ein Zustand mit Gegenständen lässt sich nicht löschen. Verworfen: beim Löschen alle Gegenstände auf einen Ersatz umstellen, weil das einen Zustandswechsel ohne Begründung wäre.

**Verlaufseinträge löschbar, nur mit eigenem Recht.** Anlass: Zustandswechsel, deren Fotos S3 nie erreicht haben. Gelöscht wird nur der Eintrag; Ort und Zustand bleiben, damit Löschen nichts am Bestand verändert. Verworfen: Einträge nur ausblenden, weil kaputte Fotos dann weiter Fehler erzeugen.

**Untermenü „Einstellungen“.** Selten genutzte Seiten (Allgemein, Kategorien, Zustände, Einheiten, Module, Kiosk-Geräte, Styleguide) liegen in einer aufklappbaren Gruppe unter Verwaltung, damit das Menü kurz bleibt. Neue Listen dieser Art kommen mit `group: 'settings'` dazu.

**Einheiten als Liste, Gegenstände speichern den Namen.** Wie bei Zuständen pflegen Admins die Liste; anders als dort speichert der Gegenstand den Namen statt eines Verweises, damit Meldungen und Verlauf ohne Nachschlagen lesbar sind. Umbenennen zieht die Gegenstände mit.

**Teilmengen ausbuchen ohne neuen Gegenstand.** „Verbraucht“ zieht Menge ab und schreibt eine Bewegung `retire` mit Menge. Verworfen: den verbrauchten Teil als eigenen ausgemusterten Gegenstand abteilen; das würde für jede Packung Kabelbinder eine Nummer verbrauchen.

**Zustände und Einheiten mit einem Recht.** `inventory.options.manage` (hieß zwischendurch `conditions.manage`) deckt beide Listen ab.

**Logo und Favicon auf S3, ausgeliefert durch Stocks.** Wie die Fotos liegen sie im privaten Bucket; Stocks liefert sie auch an Gäste aus. Grund: eine Instanz im Docker-Container hat kein beschreibbares `public/`. Verworfen: SVG als Logo, weil es Skripte enthalten kann.

**Teilmengen mit Kopie der Fotos.** Ein abgeteilter Teil bekommt Kopien der Fotos des Ursprungs (eigene Dateien auf S3), damit Löschen bei einem die Fotos des anderen nicht berührt. Kann S3 eine Datei nicht kopieren, fehlt das Foto beim Teil; das Umlagern gilt trotzdem. Verworfen: dieselbe Datei für beide, weil dann jedes Löschen prüfen müsste, wer die Datei noch nutzt.

## 2026-11-10 · Phase 0

**Validierung im Controller statt Form Requests.** Eingaben werden mit `$request->validate()` im Controller geprüft. Grund: In Phase 0 nutzt keine Validierung mehr als ein Controller; eine eigene Klasse je Formular wäre mehr Code ohne Gewinn. Verworfen: Form Requests für alles. Sobald eine Validierung zweimal gebraucht wird, wandert sie in einen Form Request.

**Keine Policies, Regeln in Actions.** Einzelrechte prüft die Route (`can:`), alles darüber hinaus (Vergaberegel, letzter Admin) die Action. Grund: Die Regeln hängen an Vorgängen, nicht an Modellen. Verworfen: Policies je Modell.

**Einfaches Anlegen und Löschen ohne Action.** Kiosk-Geräte und Einladungen werden direkt im Controller angelegt oder gelöscht. Grund: Dort gibt es keine Regeln, eine Action wäre eine Zeile Weiterreichen. Sobald eine Regel dazukommt, wird es eine Action.

**FormKit und PrimeVue entfernt.** Formulare nutzen drei eigene Felder (`UiInput`, `UiSelect`, `UiCheckbox`) mit Inertias `useForm`. Grund: FormKit musste die Fehlermeldungen von Inertia an jedem Feld einzeln verdrahten; die eigenen Felder sind kürzer als Theme und Konfiguration. PrimeVue war nur in einer Diagramm-Komponente im Einsatz. Ebenfalls entfernt: Heroicons, chart.js, lodash, axios (ungenutzt oder je eine Fundstelle).

**Tabellenzellen über Slots, kein `v-html`.** Grund: Öffentliche Anfragen bringen später fremden Text in Tabellen; mit Slots ist Maskieren von Hand nicht mehr nötig. Die Basisklasse für seitenweises Laden und Live-Update ist aus Portals übernommen.

**Module abschalten per Middleware.** Routen aller Module sind registriert, `module:<key>` antwortet mit 404. Grund: Octane hält die Routenliste bis zum Neustart. Verworfen: Routen ausgeschalteter Module nicht registrieren (stand so im Projektplan).

**Migrationen laufen auch für ausgeschaltete Module.** Grund: Ausschalten soll keine Daten löschen und Einschalten keine Nacharbeit brauchen.

**Anmeldung mit Laravel-Bordmitteln.** Login, Passwort-Reset und Einladung ohne Fortify oder Breeze. Grund: Zusammen rund 200 Zeilen, dafür kein Paket, dessen Abläufe man erst verstehen muss. Ein zweiter Faktor kommt später.

**Passwörter ab 12 Zeichen, keine Zeichenregeln.** Prüfung gegen geleakte Passwörter nur mit `CHECK_LEAKED_PASSWORDS=true`, weil sie einen externen Dienst fragt.

**Frische Migrationen statt der Historie von Portals.** Grund: Die rund 60 Migrationen beschrieben die Geschichte eines anderen Programms.

**Ein Einrichtungsschlüssel für `/setup`.** Grund: Sonst könnte jemand eine frisch gestartete Instanz vor den Betreibern einrichten und Admin werden.

**Kiosk-PIN mit sechs Ziffern, getrennte Kiosk-Sitzung.** Aus dem Strichlistensystem übernommen: Gerätekopplung per Cookie, Sperre je Person und je Gerät. Neu: sechs statt vier Ziffern, nur Konten mit `kiosk.use`, keine Anmeldung im übrigen Stocks durch die PIN.

**Keine Avatare in Phase 0.** Das Datenmodell hat keine Bildspalte; Konten zeigen Initialen. Grund: Avatare brauchen S3 und Bildverarbeitung, ohne dass Phase 0 sie nutzt.

**Ansible und Terraform von Portals entfernt.** Sie richteten einen einzelnen Server für die Erstiwoche ein (CSV-Import, Avatare). Instanzen von Stocks laufen über das Docker-Image, siehe [SETUP.md](SETUP.md). Ein Hosting-Werkzeug für viele Instanzen folgt, sobald das Hosting geklärt ist.

**Inter mitgeliefert.** Portals lud Schriften von `rsms.me` und `fonts.cdnfonts.com`; damit gingen Besucherdaten ungefragt an Dritte.

**RoadRunner entfernt, nur Swoole.** Grund: Dockerfile und Ansible von Portals nutzten bereits Swoole.

**PHP 8.5.** Laravel 13 unterstützt 8.3 bis 8.5; alle 418 Tests von Portals liefen vor dem Entkernen ohne Fehler und ohne Hinweise auf 8.5.

## 2026-11-09 · Projektplan

Grundentscheidungen aus dem Projektplan: eine Instanz je Organisation statt Mandanten; Kern plus Module Verleih, Kiosk, Öffentliche Seite; Einzelstücke und Mengenartikel; eigene Konten mit Passwort; Code und Kommentare auf Englisch, Dokumentation auf Deutsch.
