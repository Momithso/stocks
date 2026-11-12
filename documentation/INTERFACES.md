# Oberflächen

Stocks hat mehrere Oberflächen für dieselben Vorgänge. Wenn ein Vorgang neu dazukommt oder sich ändert, steht hier, wo er schon bedienbar ist und wo er noch fehlt. So weiß die nächste Arbeitsphase, was nachzuziehen ist.

**Regel:** Wer einen Vorgang hinzufügt oder ändert, trägt ihn hier ein und markiert die Oberflächen, die ihn noch nicht haben.

## Oberflächen

| Oberfläche | Wo | Stand |
|---|---|---|
| Web am PC | `resources/js/pages`, Layout `AppLayout` mit Seitenleiste | Hauptoberfläche, alles Folgende ist dort zuerst |
| Web am Handy | dieselben Seiten, untere Leiste (Dashboard, Gegenstände, Scannen, Mehr) | bedienbar; große Knöpfe, Karten statt Tabellen; als Web-App im Vollbild installierbar |
| Kiosk (Tablet) | Modul `modules/Kiosk`, Layout `Kiosk::KioskLayout`; nutzt die Seiten von PC und Handy unter `/kiosk` (`routes/kiosk.php` je Modul), siehe [KIOSK.md](KIOSK.md) | seit Phase 4: Scannen, Umlagern, Zustand, Verbraucht, Etiketten drucken, Ausgabe, Rückgabe, Prüfung und Einräumen; Restzeit mit „Verlängern“; nach jedem Vorgang zur Startseite (Sperre einstellbar) |
| Öffentliche Ansicht gescannter Etiketten | `/s/{code}` ohne Anmeldung, `resources/js/pages/Public`, Layout `PublicLayout` | seit Phase 2: Gegenstand, Box mit Inhaltsliste zum Abhaken, Standort; seit Phase 3 die Box-Kontrolle mit Pack-PIN; siehe [LABELS.md](LABELS.md#öffentliche-ansicht) |
| Verleihseite für Entleiher | `/v/{loan}/{token}` ohne Anmeldung, `Loans::Page/Show`, Layout `PublicLayout`; als eigene Web-App installierbar, siehe [LOANS.md](LOANS.md#verleihseite) | seit Phase 5: Reiter Chat, Übersicht, Material; Pack-PIN, Dokumente, Kontakte, Verlängerung, Benachrichtigungen mit Push |
| Öffentliche Seite (Katalog, Anfragen) | geplant (Gesamtplan Phase 6) | fehlt |
| Etiketten, Kamera-Scan | Seiten `Labels/*` und `Inventory/Scan`, siehe [LABELS.md](LABELS.md) | seit Phase 2 am PC und am Handy, seit Phase 4 Scannen und Drucken auch am Kiosk; Handscanner zusätzlich über Eingabefelder |

## Darstellung

| Was | Web PC | Web Handy | Kiosk | Anmeldeseite |
|---|---|---|---|---|
| Logo aus den Einstellungen | ✓ oben links, Quadrat 64 px mit Name | ✓ obere Leiste, klein mit Name | ✓ Kopfzeile, klein mit Name | ✓ Quadrat 250 px mittig über dem Namen |
| Favicon aus den Einstellungen | ✓ | ✓ | ✓ | ✓ |

## Vorgänge des Inventars

✓ = vorhanden, – = fehlt noch, n. v. = dort nicht vorgesehen

| Vorgang | Web PC | Web Handy | Kiosk | Anmerkung für spätere Oberflächen |
|---|---|---|---|---|
| Gegenstände suchen und ansehen | ✓ | ✓ | nur Scan-Karte | Suche nimmt Artikelnummer und Kennziffer, mit und ohne Bindestrich |
| Gegenstand anlegen, mit Fotos | ✓ | ✓ | n. v. | Fotos werden im Browser verkleinert (`useImageCompression`) |
| Umlagern per Scan (zwei Schritte) | ✓ | ✓ | ✓ | auf der Seite „Scannen“; die Seite „Umlagern“ gibt es am Kiosk nicht |
| Umlagern über den Gegenstand | ✓ | ✓ | n. v. | Dialog mit Ortsvorschlägen |
| Zustand ändern mit Begründung und Fotos | ✓ | ✓ | ✓ | Zustände kommen aus der Liste der Einstellungen; Fotos werden nacheinander hinzugefügt (`UiPhotoPicker`), auch einzeln mit der Handykamera |
| Rückfrage bei gebuchtem Material („Ja, trotzdem“) | ✓ | ✓ | ✓ | vor Ausmustern, Verbrauchen, Abteilen (Umlagern einer Teilmenge) und einem Zustand, der als Schaden gilt, wenn eine bestätigte Buchung oder ein Rest damit rechnet; `InventoryReservedConfirm` in den Dialogen, auf „Scannen“ und „Umlagern“ |
| Abgeteilten Teil zurückbuchen | ✓ | ✓ | ✓ | Knopf am Teil oder Ursprungsnummer als Ziel scannen |
| **Verbraucht** (Verbrauchsmaterial ausbuchen) | ✓ | ✓ | ✓ | eigener Knopf, nur wenn die Einheit Verbrauchsmaterial ist |
| Ausmustern, auch Teilmengen | ✓ | ✓ | n. v. | Recht `inventory.retire` |
| Verlaufseintrag löschen | ✓ | ✓ | n. v. | nur Admin |
| Zustände und Einheiten verwalten | ✓ | ✓ | n. v. | Untermenü Verwaltung › Einstellungen |
| Mailserver einstellen, Status, Test-Mail | ✓ | ✓ | n. v. | Verwaltung › Einstellungen › Benachrichtigungen (bis Phase 4 „E-Mail“); Status als Badge im Menü, unter dem Titel |
| Hinweis fürs Etikett pflegen | ✓ | ✓ | n. v. | Feld im Formular des Gegenstands |
| Box-Inhaltsliste als PDF | ✓ | ✓ | n. v. | Knopf auf der Box, wahlweise als Brief (DIN 5008) mit eingegebenem Empfänger, Vorschaubilder wählbar; nicht in der öffentlichen Ansicht |

## Etiketten und Scan

Seit Phase 2, siehe [LABELS.md](LABELS.md); am Kiosk seit Phase 4.

| Vorgang | Web PC | Web Handy | Kiosk | Öffentliche Ansicht | Anmerkung für spätere Oberflächen |
|---|---|---|---|---|---|
| Scannen mit Kamera (Seite „Scannen“, Aktionen am Treffer) | ✓ | ✓ | ✓ | n. v. | Kamera braucht HTTPS; `InventoryCameraScanner` ist wiederverwendbar. Gelesen wird nur, was im Quadrat liegt; Schalter „Automatisch“ in der Leiste unter dem Bild (aus: Tippen aufs Bild oder „Scannen“ liest das Quadrat einmal, sonst „Kein Code im Quadrat“), gemerkt je Gerät. Am Handy füllt die Kamera den Bildschirm und wird bei einem Treffer oben kleiner, die Karte steht darunter (nicht darüber), „Kamera groß“ blendet sie wieder aus |
| Scannen mit Handscanner ohne Feld | ✓ | ✓ | ✓ | n. v. | `useScannerInput` |
| Kamera auf der Seite „Umlagern“ | ✓ | ✓ | n. v. | n. v. | |
| „Hierher umlagern“ (alles an einen Standort scannen) | ✓ | ✓ | ✓ | n. v. | auf der Seite „Scannen“ am Standort |
| Etikett drucken (Vorschau mit echten Daten) | ✓ | ✓ | ✓ | n. v. | Direktdruck Niimbot nur Chrome/Edge (PC, Android); iPhone und iPad laden das Bild für die Niimbot-App. Am Kiosk entscheidet die Plattform des Geräts, „Als Vorlage speichern“ fehlt dort |
| Sammeldruck (Auswahl, Box-Inhalt, Fächer unter einem Standort) | ✓ | ✓ | – | n. v. | Recht `labels.print`; „Alle drucken“ oder „Einzeln“ mit Auswahl; am Kiosk nur das Etikett der Scan-Karte |
| Etiketten auf Vorrat, Reservierungen freigeben | ✓ | ✓ | n. v. | n. v. | Verwaltung › Einstellungen › Etiketten auf Vorrat |
| Gegenstand aus reservierter Nummer anlegen | ✓ | ✓ | n. v. | n. v. | Scan oder Kurzlink öffnet das Formular mit der Nummer |
| Vorlagen gestalten, Drucker verwalten | ✓ | eingeschränkt | n. v. | n. v. | Editor am Handy bedienbar, aber eng; gedacht für den PC |
| Filter „Noch ohne Etikett“ | ✓ | ✓ | n. v. | n. v. | Gegenstandsliste und Standortliste |
| Gescanntes Etikett ohne Konto ansehen | n. v. | n. v. | n. v. | ✓ | Name, Nummer, Foto, Eigentümer, Kontakt; Box-Inhalt zum Abhaken |

## Vorgänge des Verleihs

Seit Phase 3, siehe [LOANS.md](LOANS.md). Ausgabe, Rückgabe, Prüfung und Einräumen laufen seit Phase 4 auch am Kiosk (optional, Modul Kiosk); Anlegen und Verwalten bleiben am PC. Seit Phase 5 gibt es die Verleihseite für Entleiher (Spalte „Verleihseite“), die öffentliche Anfrage kommt in Phase 6.

| Vorgang | Web PC | Web Handy | Kiosk | Öffentliche Ansicht | Verleihseite | Anmerkung für spätere Oberflächen |
|---|---|---|---|---|---|---|
| Buchungen suchen, filtern, ansehen | ✓ | ✓ | nur Liste | n. v. | Stand der eigenen Buchung | am Kiosk „Heute abholen“ und „Ausgegeben“ mit Scan der Buchungsnummer, einer Box oder eines Gegenstands |
| Buchung anlegen und ändern, Kontakt im selben Dialog anlegen | ✓ | ✓ | n. v. | – (Anfrage Phase 6) | n. v. | Kontaktdaten nur mit `loans.manage`; nach der Ausgabe nur Anlass, Notiz und Veranstaltung (Formular blendet den Rest aus, der Server lehnt ihn ab) |
| „Nicht abgeholt“ (bestätigt, Rückgabetermin vorbei) | ✓ | ✓ | „Abholung verpasst“ | n. v. | n. v. | Statusabzeichen in Listen, Hinweis mit Stornieren auf der Buchung, kein „Ausgeben“ mehr; am Kiosk nur verpasste Abholungen, deren Rückgabetermin noch kommt |
| Positionen per Suche oder Scan hinzufügen | ✓ | ✓ | nur fremder Gegenstand | n. v. | n. v. | am Kiosk nur der fremde Gegenstand bei der Ausgabe, mit `loans.manage` |
| „Zur Buchung hinzufügen“ aus dem Scan-Tab | ✓ | ✓ | n. v. | n. v. | n. v. | Aktion auf der Scan-Karte, Seite zur Wahl der Buchung |
| Verfügbarkeit, Kollision, nächster freier Termin, Vormerkungen | ✓ | ✓ | n. v. | n. v. | n. v. | auf der Buchung je Position |
| Bestätigen, Stornieren, Verlängern, Pack-PIN neu setzen | ✓ | ✓ | n. v. | n. v. | Pack-PIN ändern, Verlängerung anfragen | Stornieren mit Grund, Recht `loans.cancel` |
| Kalender (Monat) und Zeitstrahl | ✓ | ✓ | n. v. | – (Phase 6) | n. v. | am Handy Liste nach Tagen, Zeitstrahl wischbar |
| Reiter „Verleih“ am Gegenstand | ✓ | ✓ | n. v. | n. v. | n. v. | Zeitstrahl und alle Buchungen des Gegenstands |
| Hinweis „ausgegeben an … bis …“ und Sperre | ✓ | ✓ | ✓ | n. v. | n. v. | Seite, Liste, Scan-Karte; Umlagern und Ausmustern gesperrt; am Kiosk auf der Scan-Karte ohne Link zur Buchung |
| Ausgabe in Schritten (Material, Person, Dokumente, Unterschriften) per Scan mit Abhaken, fremden und fehlenden Teilen | ✓ | ✓ | ✓ | n. v. | n. v. | Stand auf dem Server, auf einem anderen Gerät fortsetzbar (auch vom Kiosk am PC); Inhalt einer abgehakten Box ohne Haken „bleibt hier“ (an der Zeile und unter der Liste); bei Abholung vor dem Termin steht eine Kollision rot an der Zeile |
| Teilausgabe (weniger Stück oder einzelne Positionen), Rest später nachausgeben oder streichen | ✓ | ✓ | ✓ | n. v. | n. v. | Schritt „Rest“; Nachausgabe mit eigener Materialliste „Nachtrag“ und Unterschriften, ohne neuen Vertrag; am Kiosk unter „Heute abholen“ mit „Rest nachausgeben“; nach dem Rückgabetermin nur noch „Rest streichen“ (die Ausgabeseite zeigt dann nur den Schritt „Rest“) |
| Mangel bei der Ausgabe (beschädigt, verschmutzt, unvollständig), auf Wunsch nicht ausgeben | ✓ | ✓ | ✓ | n. v. | n. v. | Knopf „Mangel“ an der Zeile; steht auf der Materialliste und bei der Rückgabe („Schon bei der Ausgabe“) |
| Unterschrift auf dem Bildschirm oder „auf Papier unterschrieben“ | ✓ | ✓ | ✓ | n. v. | n. v. | Unterschriftenfeld `SignaturePad`, groß (am Tablet zwei nebeneinander), erst nach dem Abhaken; Foto des Blatts anhängbar |
| Leihvertrag, Materialliste, Buchungsbestätigung, Rückgabebeleg als PDF | ✓ | ✓ | nur Vorschau | n. v. | ✓ (ohne Papier-Scan) | Download von der Buchung, nur mit `loans.issue` oder `loans.manage` (auch die Unterschriften); seit Phase 5 mit QR-Code der Verleihseite, per Mail an den Entleiher und auf der Verleihseite; am Kiosk Leihvertrag und Materialliste vor der Unterschrift |
| Box-Kontrolle mit Pack-PIN | n. v. | n. v. | n. v. | ✓ | Pack-PIN sichtbar | auf `/s/{code}` der Box, ohne Konto; nach fünf falschen PINs 15 Minuten warten (je Box und Adresse) |
| Rückgabe in Schritten (Material, Übersicht, Unterschriften) per Scan, Teilrückgabe, Mängel mit Foto | ✓ | ✓ | ✓ | n. v. | n. v. | „Rückgabe dieser Buchung“ auch auf der Scan-Karte; eine abgehakte Box hakt ihren Inhalt mit ab (auch bei der Ausgabe); am Handy Name oben, Anzahl und Mangel darunter |
| Ungeprüft annehmen, spätere Prüfung | ✓ | ✓ | ✓ | n. v. | n. v. | Liste „Prüfung offen“ unter Verleih › Rückgaben |
| Einräumen per Scan (Gegenstand, dann Regal) | ✓ | ✓ | ✓ | n. v. | n. v. | Liste „Noch nicht eingeräumt“ mit Knopf „Einräumen“ je Zeile; Tipp auf den Gegenstand öffnet am PC seine Seite, am Kiosk ein Info-Fenster; „Einräumen“ auf der Scan-Karte |
| Mängel abschließen | ✓ | ✓ | ✓ | n. v. | Mängel sichtbar | Recht `loans.cancel`; was noch vermisst ist, wird als verloren ausgemustert; am Kiosk im Fenster der Buchung (Liste „Mit Mängeln“) |
| Vermisstes „Wieder da“ | ✓ | ✓ | ✓ | n. v. | n. v. | auf der Buchung (Abschnitt „Vermisst“), Hinweis mit Link auf Seite und Scan-Karte des Gegenstands; Recht `loans.issue`; am Kiosk im Fenster der Buchung |
| Kontakte, Fachschaften, Veranstaltungen | ✓ | ✓ | n. v. | n. v. | n. v. | Fachschaften unter Verwaltung › Einstellungen › Verleih |
| Leihvertrag und Verleiher einstellen | ✓ | eingeschränkt | n. v. | n. v. | n. v. | langes Textfeld, gedacht für den PC |
| Dashboard-Kachel | ✓ | ✓ | Kacheln | n. v. | n. v. | heute abholen, heute zurück, überfällig; mit `loans.issue` dazu Prüfung, Mängel, nicht eingeräumt; am Kiosk die Kacheln „Ausgabe“, „Rückgabe“ und „Einräumen“ |
| Verleihseite: Link kopieren, QR-Code, sperren, neu ausstellen | ✓ | ✓ | n. v. | n. v. | n. v. | Karte „Verleihseite“ auf der Buchung, `loans.manage`; „Neu ausstellen“ beendet den alten Link und Push an seine Geräte |
| Bestätigen mit Mail an den Entleiher (abwählbar) | ✓ | ✓ | n. v. | n. v. | n. v. | Dialog „Bestätigen“, Haken „Bestätigung per E-Mail senden“ |
| Chat mit dem Entleiher | ✓ | ✓ | – | n. v. | ✓ | Karte „Chat“ auf der Buchung (`loans.manage`), eigene Nachrichten rechts, die von Kolleg:innen links mit Namen, Reiter „Chat“ auf der Verleihseite; Zähler am Menüeintrag „Buchungen“, in der Liste und auf dem Dashboard, Filter „Ungelesene Nachricht“; am Kiosk nicht vorgesehen |
| Verlängerung anfragen / annehmen / ablehnen | annehmen, ablehnen | annehmen, ablehnen | – | n. v. | anfragen | Hinweisbox auf der Buchung; Annehmen prüft die Verfügbarkeit (`ExtendLoan`); nach Rückgabe oder Storno ist eine offene Anfrage „erledigt“ (auf der Verleihseite kein Hinweis mehr) |
| Checkliste zum Abhaken (Stand auf dem Server) | – | – | n. v. | n. v. | ✓ | nur Hilfe für den Entleiher, ab der Ausgabe; Mitglieder sehen den Stand bisher nicht (offen für später) |
| Nachrichten-Protokoll, „Erinnerung senden“ | ✓ | ✓ | n. v. | n. v. | n. v. | Karte „Nachrichten“ auf der Buchung: je Empfänger und Kanal eine Zeile |
| Benachrichtigungen einstellen, Push einschalten | Profil | Profil | n. v. | n. v. | ✓ | Mitglieder im Profil (E-Mails vorab aus, selbst einschalten), Entleiher auf der Verleihseite (gilt für alle mit dem Link, vorab an); Anleitung je Gerät |
| Telefon und E-Mail anklickbar (`tel:`, `mailto:`) | ✓ | ✓ | n. v. | ✓ (Kontakt-Mail) | ✓ | Komponente `UiContactLink`: Buchung, Kontaktliste und -seite, Verleihseite; die öffentliche Gegenstandsansicht hatte `mailto:` schon |
| Einstellungen der Verleihseite und Nachrichten | ✓ | eingeschränkt | n. v. | n. v. | n. v. | Verwaltung › Einstellungen › Verleih: Link-Dauer, Erinnerungen, Überfällig-Stufen, Abholort, Zusatztexte |

## Kiosk

Seit Phase 4, siehe [KIOSK.md](KIOSK.md).

| Vorgang | Web PC | Web Handy | Kiosk | Anmerkung |
|---|---|---|---|---|
| Tablet koppeln, Plattform wählen, Gerät sperren | ✓ | ✓ | n. v. | Verwaltung › Einstellungen › Kiosk-Geräte, Recht `kiosk.devices.manage`; Koppeln beendet eine normale Anmeldung im Browser des Tablets |
| Sitzung einstellen (Sperrzeit, Sperre nach Vorgang) | ✓ | ✓ | n. v. | Karte „Sitzung“ auf derselben Seite |
| Entsperren mit PIN | n. v. | n. v. | ✓ | PIN im eigenen Profil |
| Restzeit, „Verlängern“, „Sperren“, „Zurück“, „Startseite“ | n. v. | n. v. | ✓ | Kopfzeile des Kiosks; die letzten 20 Sekunden rot |
| Fenster einer Buchung (Positionen mit Mängeln, „Wieder da“, Mängel abschließen, Rückgabe/Prüfung) | n. v. | n. v. | ✓ | in „Mit Mängeln“ (Rückgabe-Liste), „Prüfung offen“ und „Mängel offen“; am PC die Seite der Buchung |
| Startseite „Was möchtest du tun?“ mit Kacheln je Aufgabe | n. v. | n. v. | ✓ | nach Bereich gruppiert, je Kachel ein Satz und wie viel zu tun ist |
| Als Web-App im Vollbild | ✓ | ✓ | ✓ | eigenes Manifest `/kiosk/manifest.webmanifest`; Hinweis je Plattform auf dem Sperrbildschirm |

## Benachrichtigungen (Kern)

Seit Phase 5, siehe [NOTIFICATIONS.md](NOTIFICATIONS.md).

| Vorgang | Web PC | Web Handy | Kiosk | Anmerkung |
|---|---|---|---|---|
| Benachrichtigungen je Ereignis und Kanal, Geräte mit Push, Testbenachrichtigung | ✓ | ✓ | n. v. | Karte „Benachrichtigungen“ im Profil; Push-Spalte nur mit VAPID-Schlüsseln |
| Warteschlange (fehlgeschlagene Aufträge erneut versuchen), Push-Zustand | ✓ | ✓ | n. v. | Verwaltung › Einstellungen › Benachrichtigungen (vorher „E-Mail“) |
| Zentrale Telefonnummer der Organisation | ✓ | ✓ | n. v. | Verwaltung › Einstellungen › Allgemein |

## Demo-Daten

| Vorgang | Web PC | Web Handy | Kiosk | Anmerkung |
|---|---|---|---|---|
| Testetiketten-Blatt herunterladen | ✓ | ✓ | n. v. | nur mit Demo-Daten, Verwaltung › Einstellungen › Etiketten und „Etiketten auf Vorrat“; siehe [DEMO.md](DEMO.md) |

## Menü

Seit Phase 1 kann ein Bereich des Menüs aufklappbare Gruppen haben (`Navigation::addGroup`, siehe [MODULES.md](MODULES.md)). Selten genutzte Seiten liegen in **Verwaltung › Einstellungen**. Der Kiosk nutzt das Menü nicht, er hat eigene Kacheln (`Navigation::addKioskTile`, Route `kiosk.…`). Die Seite Kiosk-Geräte liegt seit Phase 4 unter Verwaltung › Einstellungen und kommt vom Modul.
