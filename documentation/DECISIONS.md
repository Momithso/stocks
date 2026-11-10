# Entscheidungsprotokoll

Warum Stocks so gebaut ist, wie es gebaut ist. Neue Einträge oben; jeder Eintrag mit Datum, Entscheidung, Grund und verworfener Alternative.

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
