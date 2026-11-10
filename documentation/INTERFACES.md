# Oberflächen

Stocks hat mehrere Oberflächen für dieselben Vorgänge. Wenn ein Vorgang neu dazukommt oder sich ändert, steht hier, wo er schon bedienbar ist und wo er noch fehlt. So weiß die nächste Arbeitsphase, was nachzuziehen ist.

**Regel:** Wer einen Vorgang hinzufügt oder ändert, trägt ihn hier ein und markiert die Oberflächen, die ihn noch nicht haben.

## Oberflächen

| Oberfläche | Wo | Stand |
|---|---|---|
| Web am PC | `resources/js/pages`, Layout `AppLayout` mit Seitenleiste | Hauptoberfläche, alles Folgende ist dort zuerst |
| Web am Handy | dieselben Seiten, untere Leiste und Menü „Mehr“ | bedienbar; große Knöpfe, Karten statt Tabellen |
| Kiosk (Tablet) | `resources/js/pages/Kiosk`, Layout `KioskLayout`, Routen mit `kiosk.device` und `kiosk` | nur Sperrbildschirm und Startseite mit Kacheln (Phase 0); Inventar fehlt noch ganz |
| Öffentliche Seite | geplant (Gesamtplan Phase 6) | fehlt |
| Etiketten, Kamera-Scan | geplant als eigene Arbeitsphase | fehlt; heute nur Handscanner über Eingabefelder |

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
| Zustand ändern mit Begründung und Fotos | ✓ | ✓ | – | Zustände kommen aus der Liste der Einstellungen |
| Abgeteilten Teil zurückbuchen | ✓ | ✓ | – | Knopf am Teil oder Ursprungsnummer als Ziel scannen |
| **Verbraucht** (Verbrauchsmaterial ausbuchen) | ✓ | ✓ | – | eigener Knopf, nur wenn die Einheit Verbrauchsmaterial ist; gehört auf den Kiosk |
| Ausmustern, auch Teilmengen | ✓ | ✓ | n. v. | Recht `inventory.retire` |
| Verlaufseintrag löschen | ✓ | ✓ | n. v. | nur Admin |
| Zustände und Einheiten verwalten | ✓ | ✓ | n. v. | Untermenü Verwaltung › Einstellungen |

## Menü

Seit Phase 1 kann ein Bereich des Menüs aufklappbare Gruppen haben (`Navigation::addGroup`, siehe [MODULES.md](MODULES.md)). Selten genutzte Seiten liegen in **Verwaltung › Einstellungen**. Der Kiosk nutzt das Menü nicht, er hat eigene Kacheln (`Navigation::addKioskTile`).
