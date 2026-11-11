# Oberflächen

Stocks hat mehrere Oberflächen für dieselben Vorgänge. Wenn ein Vorgang neu dazukommt oder sich ändert, steht hier, wo er schon bedienbar ist und wo er noch fehlt. So weiß die nächste Arbeitsphase, was nachzuziehen ist.

**Regel:** Wer einen Vorgang hinzufügt oder ändert, trägt ihn hier ein und markiert die Oberflächen, die ihn noch nicht haben.

## Oberflächen

| Oberfläche | Wo | Stand |
|---|---|---|
| Web am PC | `resources/js/pages`, Layout `AppLayout` mit Seitenleiste | Hauptoberfläche, alles Folgende ist dort zuerst |
| Web am Handy | dieselben Seiten, untere Leiste (Dashboard, Gegenstände, Scannen, Mehr) | bedienbar; große Knöpfe, Karten statt Tabellen; als Web-App im Vollbild installierbar |
| Kiosk (Tablet) | `resources/js/pages/Kiosk`, Layout `KioskLayout`, Routen mit `kiosk.device` und `kiosk` | nur Sperrbildschirm und Startseite mit Kacheln (Phase 0); Inventar, Scan und Druck fehlen noch ganz (kommen mit Phase 4) |
| Öffentliche Ansicht gescannter Etiketten | `/s/{code}` ohne Anmeldung, `resources/js/pages/Public`, Layout `PublicLayout` | seit Phase 2: Gegenstand, Box mit Inhaltsliste zum Abhaken, Standort; seit Phase 3 die Box-Kontrolle mit Pack-PIN; siehe [LABELS.md](LABELS.md#öffentliche-ansicht) |
| Verleihseite für Entleiher | geplant (Gesamtplan Phase 5) | fehlt; bis dahin laden Mitglieder die PDFs von der Buchung herunter |
| Öffentliche Seite (Katalog, Anfragen) | geplant (Gesamtplan Phase 6) | fehlt |
| Etiketten, Kamera-Scan | Seiten `Labels/*` und `Inventory/Scan`, siehe [LABELS.md](LABELS.md) | seit Phase 2 am PC und am Handy; Handscanner zusätzlich über Eingabefelder |

## Darstellung

| Was | Web PC | Web Handy | Kiosk | Anmeldeseite |
|---|---|---|---|---|
| Logo aus den Einstellungen | ✓ oben links, Quadrat 64 px mit Name | ✓ obere Leiste, klein mit Name | ✓ Kopfzeile, klein mit Name | ✓ Quadrat 250 px mittig über dem Namen |
| Favicon aus den Einstellungen | ✓ | ✓ | ✓ | ✓ |

## Vorgänge des Inventars

✓ = vorhanden, – = fehlt noch, n. v. = dort nicht vorgesehen

| Vorgang | Web PC | Web Handy | Kiosk | Anmerkung für spätere Oberflächen |
|---|---|---|---|---|
| Gegenstände suchen und ansehen | ✓ | ✓ | – | Suche nimmt Artikelnummer und Kennziffer, mit und ohne Bindestrich |
| Gegenstand anlegen, mit Fotos | ✓ | ✓ | n. v. | Fotos werden im Browser verkleinert (`useImageCompression`) |
| Umlagern per Scan (zwei Schritte) | ✓ | ✓ | – | Seite „Umlagern“; Kiosk soll das später auch können |
| Umlagern über den Gegenstand | ✓ | ✓ | – | Dialog mit Ortsvorschlägen |
| Zustand ändern mit Begründung und Fotos | ✓ | ✓ | – | Zustände kommen aus der Liste der Einstellungen; Fotos werden nacheinander hinzugefügt (`UiPhotoPicker`), auch einzeln mit der Handykamera |
| Abgeteilten Teil zurückbuchen | ✓ | ✓ | – | Knopf am Teil oder Ursprungsnummer als Ziel scannen |
| **Verbraucht** (Verbrauchsmaterial ausbuchen) | ✓ | ✓ | – | eigener Knopf, nur wenn die Einheit Verbrauchsmaterial ist; gehört auf den Kiosk |
| Ausmustern, auch Teilmengen | ✓ | ✓ | n. v. | Recht `inventory.retire` |
| Verlaufseintrag löschen | ✓ | ✓ | n. v. | nur Admin |
| Zustände und Einheiten verwalten | ✓ | ✓ | n. v. | Untermenü Verwaltung › Einstellungen |
| Mailserver einstellen, Status, Test-Mail | ✓ | ✓ | n. v. | Verwaltung › Einstellungen › E-Mail; Status als Badge im Menü |
| Hinweis fürs Etikett pflegen | ✓ | ✓ | n. v. | Feld im Formular des Gegenstands |
| Box-Inhaltsliste als PDF | ✓ | ✓ | – | Knopf auf der Box, wahlweise als Brief (DIN 5008) mit eingegebenem Empfänger, Vorschaubilder wählbar; nicht in der öffentlichen Ansicht |

## Etiketten und Scan

Seit Phase 2, siehe [LABELS.md](LABELS.md). Der Kiosk bekommt Druck und Scan erst mit Phase 4; dort fehlen alle Zeilen dieser Tabelle.

| Vorgang | Web PC | Web Handy | Kiosk | Öffentliche Ansicht | Anmerkung für spätere Oberflächen |
|---|---|---|---|---|---|
| Scannen mit Kamera (Seite „Scannen“, Aktionen am Treffer) | ✓ | ✓ | – | n. v. | Kamera braucht HTTPS; `InventoryCameraScanner` ist wiederverwendbar. Gelesen wird nur, was im Quadrat liegt; Schalter „Automatisch“ in der Leiste unter dem Bild (aus: Tippen aufs Bild oder „Scannen“ liest das Quadrat einmal, sonst „Kein Code im Quadrat“), gemerkt je Gerät. Am Handy füllt die Kamera den Bildschirm und wird bei einem Treffer oben kleiner, die Karte steht darunter (nicht darüber), „Kamera groß“ blendet sie wieder aus |
| Scannen mit Handscanner ohne Feld | ✓ | ✓ | – | n. v. | `useScannerInput`; der Kiosk soll das später auch können |
| Kamera auf der Seite „Umlagern“ | ✓ | ✓ | – | n. v. | |
| „Hierher umlagern“ (alles an einen Standort scannen) | ✓ | ✓ | – | n. v. | auf der Seite „Scannen“ am Standort |
| Etikett drucken (Vorschau mit echten Daten) | ✓ | ✓ | – | n. v. | Direktdruck Niimbot nur Chrome/Edge (PC, Android); iPhone lädt das Bild für die Niimbot-App |
| Sammeldruck (Auswahl, Box-Inhalt, Fächer unter einem Standort) | ✓ | ✓ | – | n. v. | Recht `labels.print`; „Alle drucken“ oder „Einzeln“ mit Auswahl |
| Etiketten auf Vorrat, Reservierungen freigeben | ✓ | ✓ | n. v. | n. v. | Verwaltung › Einstellungen › Etiketten auf Vorrat |
| Gegenstand aus reservierter Nummer anlegen | ✓ | ✓ | n. v. | n. v. | Scan oder Kurzlink öffnet das Formular mit der Nummer |
| Vorlagen gestalten, Drucker verwalten | ✓ | eingeschränkt | n. v. | n. v. | Editor am Handy bedienbar, aber eng; gedacht für den PC |
| Filter „Noch ohne Etikett“ | ✓ | ✓ | – | n. v. | Gegenstandsliste und Standortliste |
| Gescanntes Etikett ohne Konto ansehen | n. v. | n. v. | n. v. | ✓ | Name, Nummer, Foto, Eigentümer, Kontakt; Box-Inhalt zum Abhaken |

## Vorgänge des Verleihs

Seit Phase 3, siehe [LOANS.md](LOANS.md). Ausgabe und Rückgabe laufen am PC und Handy; der Kiosk ist optional und bekommt sie in Phase 4, dort fehlen alle Zeilen dieser Tabelle. Die Verleihseite für Entleiher kommt in Phase 5, die öffentliche Anfrage in Phase 6.

| Vorgang | Web PC | Web Handy | Kiosk | Öffentliche Ansicht | Anmerkung für spätere Oberflächen |
|---|---|---|---|---|---|
| Buchungen suchen, filtern, ansehen | ✓ | ✓ | – | n. v. | Liste mit Live-Update; am Handy unter „Mehr“ |
| Buchung anlegen und ändern, Kontakt im selben Dialog anlegen | ✓ | ✓ | n. v. | – (Anfrage Phase 6) | Kontaktdaten nur mit `loans.manage` |
| Positionen per Suche oder Scan hinzufügen | ✓ | ✓ | – | n. v. | Suche zeigt die Verfügbarkeit im Zeitraum |
| „Zur Buchung hinzufügen“ aus dem Scan-Tab | ✓ | ✓ | – | n. v. | Aktion auf der Scan-Karte, Seite zur Wahl der Buchung |
| Verfügbarkeit, Kollision, nächster freier Termin, Vormerkungen | ✓ | ✓ | – | n. v. | auf der Buchung je Position |
| Bestätigen, Stornieren, Verlängern, Pack-PIN neu setzen | ✓ | ✓ | n. v. | n. v. | Stornieren mit Grund, Recht `loans.cancel` |
| Kalender (Monat) und Zeitstrahl | ✓ | ✓ | – | – (Phase 6) | am Handy Liste nach Tagen, Zeitstrahl wischbar |
| Reiter „Verleih“ am Gegenstand | ✓ | ✓ | – | n. v. | Zeitstrahl und alle Buchungen des Gegenstands |
| Hinweis „ausgegeben an … bis …“ und Sperre | ✓ | ✓ | – | n. v. | Seite, Liste, Scan-Karte; Umlagern und Ausmustern gesperrt |
| Ausgabe per Scan mit Abhaken, fremden und fehlenden Teilen | ✓ | ✓ | – | n. v. | Stand auf dem Server, auf einem anderen Gerät fortsetzbar |
| Unterschrift auf dem Bildschirm oder „auf Papier unterschrieben“ | ✓ | ✓ | – | – (Phase 5) | Unterschriftenfeld `SignaturePad`; Foto des Blatts anhängbar |
| Leihvertrag, Materialliste, Buchungsbestätigung, Rückgabebeleg als PDF | ✓ | ✓ | – | – (Phase 5) | Download von der Buchung; E-Mails kommen in Phase 5 |
| Box-Kontrolle mit Pack-PIN | n. v. | n. v. | n. v. | ✓ | auf `/s/{code}` der Box, ohne Konto |
| Rückgabe per Scan, Teilrückgabe, Mängel mit Foto | ✓ | ✓ | – | n. v. | „Rückgabe dieser Buchung“ auch auf der Scan-Karte; eine abgehakte Box hakt ihren Inhalt mit ab (auch bei der Ausgabe); am Handy Name oben, Anzahl und Mangel darunter |
| Ungeprüft annehmen, spätere Prüfung | ✓ | ✓ | – | n. v. | Liste „Prüfung offen“ unter Verleih › Rückgaben |
| Einräumen per Scan (Gegenstand, dann Regal) | ✓ | ✓ | – | n. v. | Liste „Noch nicht eingeräumt“; „Einräumen“ auf der Scan-Karte |
| Mängel abschließen | ✓ | ✓ | n. v. | n. v. | Recht `loans.cancel`; was noch vermisst ist, wird als verloren ausgemustert |
| Vermisstes „Wieder da“ | ✓ | ✓ | – | n. v. | auf der Buchung (Abschnitt „Vermisst“), Hinweis mit Link auf Seite und Scan-Karte des Gegenstands; Recht `loans.issue` |
| Kontakte, Fachschaften, Veranstaltungen | ✓ | ✓ | n. v. | n. v. | Fachschaften unter Verwaltung › Einstellungen › Verleih |
| Leihvertrag und Verleiher einstellen | ✓ | eingeschränkt | n. v. | n. v. | langes Textfeld, gedacht für den PC |
| Dashboard-Kachel | ✓ | ✓ | – | n. v. | heute abholen, heute zurück, überfällig; mit `loans.issue` dazu Prüfung, Mängel, nicht eingeräumt |

## Demo-Daten

| Vorgang | Web PC | Web Handy | Kiosk | Anmerkung |
|---|---|---|---|---|
| Testetiketten-Blatt herunterladen | ✓ | ✓ | n. v. | nur mit Demo-Daten, Verwaltung › Einstellungen › Etiketten und „Etiketten auf Vorrat“; siehe [DEMO.md](DEMO.md) |

## Menü

Seit Phase 1 kann ein Bereich des Menüs aufklappbare Gruppen haben (`Navigation::addGroup`, siehe [MODULES.md](MODULES.md)). Selten genutzte Seiten liegen in **Verwaltung › Einstellungen**. Der Kiosk nutzt das Menü nicht, er hat eigene Kacheln (`Navigation::addKioskTile`).
