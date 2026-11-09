# Entscheidungsprotokoll

Warum Stocks so gebaut ist, wie es gebaut ist. Neue Einträge oben; jeder Eintrag mit Datum, Entscheidung, Grund und verworfener Alternative.

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
