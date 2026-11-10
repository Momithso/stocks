# Oberflächen

Stocks hat mehrere Oberflächen für dieselben Vorgänge. Wenn ein Vorgang neu dazukommt oder sich ändert, steht hier, wo er schon bedienbar ist und wo er noch fehlt. So weiß die nächste Arbeitsphase, was nachzuziehen ist.

**Regel:** Wer einen Vorgang hinzufügt oder ändert, trägt ihn hier ein und markiert die Oberflächen, die ihn noch nicht haben.

## Oberflächen

| Oberfläche | Wo | Stand |
|---|---|---|
| Web am PC | `resources/js/pages`, Layout `AppLayout` mit Seitenleiste | Hauptoberfläche, alles Folgende ist dort zuerst |
| Web am Handy | dieselben Seiten, untere Leiste (Dashboard, Gegenstände, Scannen, Mehr) | bedienbar; große Knöpfe, Karten statt Tabellen; als Web-App im Vollbild installierbar |
| Kiosk (Tablet) | `resources/js/pages/Kiosk`, Layout `KioskLayout`, Routen mit `kiosk.device` und `kiosk` | nur Sperrbildschirm und Startseite mit Kacheln (Phase 0); Inventar, Scan und Druck fehlen noch ganz (kommen mit Phase 4) |
| Öffentliche Ansicht gescannter Etiketten | `/s/{code}` ohne Anmeldung, `resources/js/pages/Public`, Layout `PublicLayout` | seit Phase 2: Gegenstand, Box mit Inhaltsliste zum Abhaken, Standort; siehe [LABELS.md](LABELS.md#öffentliche-ansicht) |
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
| Hinweis fürs Etikett pflegen | ✓ | ✓ | n. v. | Feld im Formular des Gegenstands |
| Box-Inhaltsliste als PDF | ✓ | ✓ | – | Knopf auf der Box, wahlweise als Brief (DIN 5008) mit eingegebenem Empfänger, Vorschaubilder wählbar; nicht in der öffentlichen Ansicht |

## Etiketten und Scan

Seit Phase 2, siehe [LABELS.md](LABELS.md). Der Kiosk bekommt Druck und Scan erst mit Phase 4; dort fehlen alle Zeilen dieser Tabelle.

| Vorgang | Web PC | Web Handy | Kiosk | Öffentliche Ansicht | Anmerkung für spätere Oberflächen |
|---|---|---|---|---|---|
| Scannen mit Kamera (Seite „Scannen“, Aktionen am Treffer) | ✓ | ✓ | – | n. v. | Kamera braucht HTTPS; `InventoryCameraScanner` ist wiederverwendbar |
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

## Menü

Seit Phase 1 kann ein Bereich des Menüs aufklappbare Gruppen haben (`Navigation::addGroup`, siehe [MODULES.md](MODULES.md)). Selten genutzte Seiten liegen in **Verwaltung › Einstellungen**. Der Kiosk nutzt das Menü nicht, er hat eigene Kacheln (`Navigation::addKioskTile`).
