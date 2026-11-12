# Verleih

Das Modul `modules/Loans` (Schlüssel `loans`) verwaltet, wer was von wann bis wann leiht: Kontakte, Veranstaltungen, Buchungen mit Verfügbarkeit, Kalender, Ausgabe und Rückgabe per Scan mit Leihvertrag und Unterschrift. Im Code heißt die Buchung `Loan`. Ausgabe und Rückgabe laufen am PC und Handy und mit dem Modul Kiosk auch am Tablet ([KIOSK.md](KIOSK.md#verleih-am-kiosk)); die Verleihseite für Entleiher und E-Mails kommen in Phase 5.

## Inhalt

- [Ablauf einer Buchung](#ablauf-einer-buchung)
- [Kontakte, Fachschaften, Veranstaltungen](#kontakte-fachschaften-veranstaltungen)
- [Buchungen und Positionen](#buchungen-und-positionen)
- [Verfügbarkeit](#verfügbarkeit)
- [Kalender](#kalender)
- [Ausgabe](#ausgabe)
- [Dokumente und Unterschrift](#dokumente-und-unterschrift)
- [Pack-PIN](#pack-pin)
- [Rückgabe](#rückgabe)
- [Prüfung und Einräumen](#prüfung-und-einräumen)
- [Am Gegenstand](#am-gegenstand)
- [Einstellungen](#einstellungen)
- [Rechte](#rechte)
- [Datenbank](#datenbank)
- [Routen](#routen)
- [Sicherheit](#sicherheit)
- [Code](#code)

## Ablauf einer Buchung

| Status | Wert | Bedeutung | Belegt Gegenstände |
|---|---|---|---|
| Entwurf | `draft` | wird zusammengestellt | nein; im Kalender schraffiert, an den Gegenständen als Vormerkung |
| Bestätigt | `confirmed` | Verantwortlicher vollständig, alles frei, Pack-PIN gesetzt | ja |
| Ausgegeben | `issued` | gescannt und unterschrieben, die Frist läuft | ja |
| Angenommen, ungeprüft | `unchecked` | alles zurück, Prüfung offen | nein; Boxen mit Hinweis neu verleihbar |
| Abgeschlossen | `closed` | zurück und geprüft, ohne Mängel | nein |
| Abgeschlossen mit Mängeln | `defects` | etwas fehlt, ist beschädigt oder verschmutzt; offen bis `loans.cancel` die Mängel mit Notiz abschließt | nein |
| Storniert | `cancelled` | mit Grund, nur vor der Ausgabe | nein |

„Überfällig“ ist kein Status: Eine ausgegebene Buchung ist überfällig, sobald `return_at` vorbei ist und noch etwas draußen ist (`Loan::isOverdue()`, Tage mit `overdueDays()`). Sie belegt, was draußen ist, bis die Rückgabe gebucht ist. Ein Rest, der nie ausgegeben wurde, macht sie nicht überfällig und belegt nach dem Rückgabetermin nichts mehr.

„Nicht abgeholt“ ist ebenfalls kein Status: Eine bestätigte Buchung, deren Rückgabetermin vorbei ist (`Loan::isNotPickedUp()`, Scope `notPickedUp`). Sie reserviert nichts mehr, lässt sich nicht mehr ausgeben, steht in Listen als „Nicht abgeholt“ und bietet auf ihrer Seite das Stornieren an; mit einem neuen Zeitraum (Bearbeiten) gilt sie wieder.

Den Status ändern nur die Actions in `modules/Loans/Actions`. Entwürfe lassen sich löschen, alles andere wird storniert.

## Kontakte, Fachschaften, Veranstaltungen

**Kontakt** (`contacts`): Entleiher und Verantwortliche haben kein Konto. Name, E-Mail, Telefon, Anschrift, Fachschaft oder privat, Notiz. „Vollständig“ heißt Name, E-Mail, Telefon, Straße, PLZ und Ort (`Contact::REQUIRED`); nur ein vollständiger Kontakt kann eine Buchung verantworten. Kontakte legt man auch im Formular der Buchung an (Dialog, JSON an `POST /loans/contacts`). Ein Kontakt mit Buchungen oder Veranstaltungen lässt sich nicht löschen; Aufbewahrungsfristen kommen mit Phase 7. Kontaktdaten sieht nur, wer `loans.manage` hat, alle anderen sehen in Buchungen nur den Namen (`Contact::summary(false)`).

**Fachschaft** (`councils`): Nummer, Name, aktiv. Die Migration legt FSR 1 bis FSR 10 an. Gepflegt unter Verwaltung › Einstellungen › Verleih; eine Fachschaft mit Kontakten lässt sich nur ausschalten. Ist keine aktiv, bleibt nur „privat“.

**Veranstaltung** (`events`): Name, Zeitraum, Ort, Verantwortlicher, Notiz. Nur eine Klammer: Ihre Seite zeigt die Buchungen und eine gemeinsame Materialliste; „Buchung dazu“ übernimmt Zeitraum und Verantwortlichen als Vorschlag.

## Buchungen und Positionen

Eine Buchung hat eine Nummer (`B-2026-0042`: Präfix aus `loans.number_prefix`, Jahr, Zähler je Jahr in `number_sequences`), die Art (`external` = Verleih, `internal` = Eigenbedarf), Entleiher und Verantwortlichen (Kontakte, oft derselbe), bei Eigenbedarf stattdessen ein verantwortliches Mitglied, Abholung und Rückgabe mit Uhrzeit, optional eine Veranstaltung, Anlass und Notiz.

Positionen (`loan_items`) sind Gegenstände, Boxen oder eine Anzahl eines Mengenartikels. Sie kommen per Suche (mit der Verfügbarkeit im Zeitraum) oder per Scan dazu, auch aus dem Scan-Tab („Zur Buchung hinzufügen“ → Seite zur Wahl der Buchung oder neue Buchung damit). Regeln (`ChangePositions`):

- Ausgemusterte und defekte Gegenstände (Zustand mit `is_defect`) sind nicht verleihbar.
- Ein Einzelstück nur einmal; ein Mengenartikel ein zweites Mal erhöht die Anzahl.
- Was in einer Box der Buchung liegt, kommt nicht noch einmal einzeln dazu. Eine Box steht bis zur Ausgabe für ihren aktuellen Inhalt.
- Ein Entwurf nimmt alles und zeigt Kollisionen; eine bestätigte Buchung nimmt nur, was frei ist.
- Nach der Ausgabe sind Positionen und Zeitraum fest. Was dazukommen soll, wird eine zweite Buchung in derselben Veranstaltung.

**Bestätigen** (`ConfirmLoan`): nur ein Entwurf, mit mindestens einer Position, einem vollständigen Verantwortlichen (Eigenbedarf: Mitglied) und jeder Position frei. Die Buchung bekommt eine zufällige sechsstellige Pack-PIN. Wer zuerst bestätigt, bekommt den Gegenstand; andere Entwürfe zeigen danach die Kollision.

**Ändern** nach der Bestätigung prüft die Verfügbarkeit neu und lehnt ab, wenn etwas nicht frei ist. **Verlängern** (`ExtendLoan`) einer ausgegebenen Buchung geht, wenn bis zum neuen Termin keine Folgebuchung kollidiert. **Stornieren** (`CancelLoan`, Recht `loans.cancel`) verlangt einen Grund. Alle Änderungen stehen im Änderungsprotokoll.

**Eigenbedarf** läuft wie ein Verleih, nur ohne Vertrag und Unterschrift: Scan und Materialliste.

## Verfügbarkeit

`Modules\Loans\Support\Availability`. Ein Gegenstand ist in einem Zeitraum belegt, wenn eine bestätigte oder ausgegebene Buchung ihn in einem überlappenden Zeitraum enthält:

- direkt, oder über eine Box, in der er liegt: eine gebuchte Box belegt ihren ganzen Inhalt;
- ist ein Gegenstand aus einer Box einzeln gebucht, lässt sich die Box nur mit Hinweis buchen („Unvollständig: …“);
- Mengenartikel zählen: frei = Bestand am Ort + mit Buchungen ausgegebene Teile − überlappend gebuchte Anzahl. Ein ausgegebener Teil (abgeteilt) ist selbst ganz belegt;
- eine überfällige Buchung überlappt jeden späteren Zeitraum, bis die Gegenstände zurück sind;
- zwischen zwei Buchungen bleibt der Puffer `loans.buffer_hours` frei (Vorgabe 0);
- schon zurückgebuchte Zeilen einer Teilrückgabe belegen nichts mehr.

`check()` liefert je Position: `problem` (nicht verleihbar), `conflicts` (andere Buchungen), `drafts` (Vormerkungen mit Nummer, Zeitraum, Ersteller), `hints` (unvollständige Box, ungeprüft zurück), `free` (Mengen), `next_free` (nächster Beginn mit derselben Dauer; leer, solange eine überfällige Buchung den Gegenstand hält) und `blocked`.

## Kalender

Verleih › Kalender lädt die Buchungen des gezeigten Zeitraums als JSON (`/loans/calendar/loans`, `/loans/calendar/timeline`, höchstens 120 Tage).

- **Monat** (`MonthCalendar.vue`): jede Buchung als Balken von Abholung bis Rückgabe, Farbe nach Status, Entwürfe schraffiert, überfällige rot bis heute. Klick auf einen Balken öffnet die Buchung, Klick auf einen Tag beginnt eine neue mit diesem Datum. Am Handy wird daraus eine Liste nach Tagen mit Abholungen und Rückgaben.
- **Zeitstrahl** (`Timeline.vue`): eine Zeile je Gegenstand mit Buchungen im Zeitraum (mit Suche auch Gegenstände ohne Buchung), Filter nach Kategorie; waagerecht scroll- und wischbar. Der Inhalt einer gebuchten Box bekommt den Balken mit.
- **Reiter „Verleih“** auf der Seite eines Gegenstands (`ItemTab.vue`, `/loans/items/{item}`): sein Zeitstrahl (ohne Namensspalte, scrollt in sich und beginnt bei gestern) und alle seine Buchungen, auch die der Box, in der er gerade liegt (Boxen in Boxen mit). Nach der Ausgabe ist der Inhalt eine eigene Zeile der Buchung und bleibt so am Gegenstand stehen, auch wenn er später die Box verlässt.

Die Liste Verleih › Buchungen hat Filter nach Status (auch „offen“ und „überfällig“), Zeitraum, Veranstaltung und Kontakt (`?contact=`) und nutzt die Tabellen mit Live-Update aus dem Kern.

## Ausgabe

„Ausgeben“ auf einer bestätigten Buchung (`/loans/{id}/issue`, `IssueLoan`). Der Stand liegt auf dem Server; man kann unterbrechen und auf einem anderen Gerät weitermachen.

Die Seite führt in Schritten mit einem Strahl oben (`components/Steps.vue`): **Material → Rest → Person → Dokumente → Unterschriften** (Eigenbedarf ohne Person; „Rest“ nur, wenn etwas nicht abgehakt ist). „Weiter“ und die späteren Schritte gehen, sobald mindestens eine Position abgehakt ist; wird auf einem anderen Gerät alles wieder offen, springt die Seite zurück zum Material. Fertige Schritte lassen sich im Strahl wieder öffnen, eine schon geleistete Unterschrift bleibt dabei stehen. Die Unterschriftenfelder sind groß (am Tablet nebeneinander).

1. **Vorbereiten:** Der Inhalt jeder Box wird zu Zeilen unter der Box (`parent_id`), Boxen in Boxen eingerückt; beim Öffnen und beim Abschluss wird er mit dem aktuellen Inhalt abgeglichen.
2. **Scannen** (Kamera, Handscanner, Tippen): Jeder Scan hakt eine Zeile ab (`checked_by_scan`); von Hand geht es auch. Eine abgehakte Box hakt ihren Inhalt mit ab (Boxen in Boxen auch); ist der letzte Inhalt einer Box abgehakt, ist es auch die Box. Wird etwas wieder offen, sind es auch die Boxen darüber. Bei Mengenartikeln bestätigt man die Anzahl; weniger als gebucht teilt die Position, der Rest bleibt eine eigene Position (wieder abgehakt oder zurückgenommen wird sie wieder eins). Ein fremder Gegenstand gibt eine Warnung und, wenn er frei ist, „Zur Buchung hinzufügen“. Kontrollen per Pack-PIN stehen an der Box.
   **Mangel bei der Ausgabe:** an jeder Zeile „Mangel“ mit Art (beschädigt, verschmutzt, unvollständig), Notiz und Fotos (`loan_items.issue_state`, `issue_note`, `RecordDefect`). „Beschädigt“ ändert den Zustand mit Verweis auf die Buchung, die anderen schreiben einen Eintrag in den Verlauf. „Nicht ausgeben“ hält eine Position zurück, sie wird Rest. Der Mangel steht auf der Materialliste, bei der Rückgabe an der Zeile („Schon bei der Ausgabe: …“), im Buchungsfenster und auf dem Rückgabebeleg; niemand haftet dafür bei der Rückgabe.
   **Rest (Teilausgabe):** Was nicht abgehakt ist, geht nicht mit. Im Schritt „Rest“ steht jede solche Position mit „Später ausgeben“ (bleibt gebucht und reserviert) oder „Streichen“ (fliegt aus der Buchung). Eine Teilausgabe ist kein Mangel. Die letzte Position einer bestätigten Buchung lässt sich nicht streichen, dafür gibt es „Stornieren“.
   **Ungeprüft zurückgekommene Box:** Ihr Inhalt wird einzeln abgehakt, ein Scan der Box selbst wird abgelehnt; mit dem letzten Inhalt ist die Box abgehakt. Beim Abschluss prüft der Server, dass in jeder Box, die geht, alles abgehakt ist (auch was nach dem Abhaken in die Box gelegt wurde).
3. **Verantwortliche Person:** Holt jemand anderes ab, wird sie vorher geändert; der neue Kontakt muss vollständig sein.
4. **Dokumente:** Leihvertrag und Materialliste als Vorschau-PDF ohne Unterschriften, der Vertrag auch zum Lesen auf der Seite.
5. **Unterschriften:** erst die verantwortliche Person, dann der Herausgeber (angemeldet, `loans.issue`). Oder „auf Papier unterschrieben“ mit optionalem Foto oder Scan des Blatts.
6. **Abschluss:** Nur das Abgehakte geht: Inhalt und Zustände werden eingefroren, die Dokumente mit Prüfsumme gespeichert (`loan_items.issued_at`, `issue_document_id`), die Gegenstände verlassen ihren Ort (`MoveItem::takeAway`, Bewegung `issue` mit Buchungsnummer). Der Stammplatz bleibt, der Inhalt einer Box bleibt in der Box. Von Mengenartikeln wird die Anzahl abgeteilt (`issued_item_id`), wie beim Umlagern einer Teilmenge. Eine Ausgabe vor dem Abholtermin setzt die Abholung auf jetzt, wenn nichts kollidiert.

Ausgegebene Gegenstände sind gesperrt: kein Umlagern, Teilen oder Ausmustern (siehe [Am Gegenstand](#am-gegenstand)).

**Nachausgabe:** Hat eine ausgegebene Buchung noch einen Rest, zeigt die Buchung „Rest ausgeben“ (am Kiosk: „Heute abholen“ mit „Rest nachausgeben“, oder Scan eines Rest-Gegenstands). Es sind dieselben Schritte ohne „Person“; es entsteht eine eigene **Materialliste „Nachtrag“**, die beide wieder unterschreiben. Der Leihvertrag wird nur bei der ersten Ausgabe unterschrieben und gilt weiter. Zurückgenommen wird nur, was ausgegeben ist; die Buchung schließt erst, wenn alles Ausgegebene zurück ist und kein Rest mehr offen ist (gestrichener Rest zählt nicht). Nach dem Rückgabetermin reserviert der Rest nichts mehr und lässt sich nur noch streichen („Rest streichen“, Hinweis auf Buchung und Ausgabeseite); wer ihn doch noch ausgeben will, verlängert erst die Buchung.

**Doppelt gesendet:** Ausgabe, Rückgabe, Prüfung, Mängel abschließen, Einräumen und „Wieder da“ sperren ihre Buchung bzw. Zeile in der Transaktion (`Loan::lockForTask()`, `lockForUpdate`) und prüfen dort Status und Zeilen neu. Ein zweiter Tipp oder eine wiederholte Anfrage findet alles gebucht und bricht mit einer Meldung ab. Vorgänge, die erst prüfen und dann reservieren (Bestätigen, Position hinzufügen oder ändern, neuer Zeitraum, Verlängern, vorzeitige Ausgabe, geänderte Menge bei der Ausgabe), laufen nacheinander über eine Cache-Sperre (`Availability::exclusively()`).

**Nach der Ausgabe** lassen sich nur noch Anlass, Notiz und Veranstaltung ändern; Art, Entleiher, verantwortliche Person und Zeitraum stehen so fest, wie sie im Vertrag stehen (`SaveLoan`). Den Rückgabetermin ändert „Verlängern“.

## Dokumente und Unterschrift

| Dokument | Inhalt | Entsteht |
|---|---|---|
| Buchungsbestätigung | Zeitraum, Personen, Positionen, Pack-PIN | auf Knopfdruck aus dem aktuellen Stand (`loans.manage`) |
| Leihvertrag | Logo, Verleiher, Entleiher und Verantwortlicher mit Kontaktdaten, Zeitraum, Vertragstext, Unterschriften, Prüfsumme | bei der Ausgabe (nicht bei Eigenbedarf) |
| Materialliste | Positionen mit Nummer, Menge und Zustand, Boxen mit Inhalt eingerückt, Kästchen, Vermerke zu Box-Kontrollen und ungeprüften Boxen, Unterschriften | bei der Ausgabe |
| Rückgabebeleg | zurückgegebene Positionen mit Stand, Vermerken und „von Hand abgehakt“, Stempel „Ungeprüft angenommen, Mängel vorbehalten“ falls zutreffend, Unterschriften | bei jeder Rückgabe |

Gespeichert wird je Dokument der eingefrorene Inhalt (`loan_documents.snapshot`) und seine Prüfsumme (SHA-256 über das JSON mit sortierten Schlüsseln, `Support\Snapshot`). Das PDF entsteht beim Herunterladen mit dompdf aus diesem Inhalt (`Support\LoanDocuments`, Ansichten `modules/Loans/resources/views/pdf`), zeigt also immer genau das Unterschriebene; die Prüfsumme steht in der Fußzeile. Die Buchung zeigt „unverändert seit der Unterschrift“, solange der gespeicherte Inhalt zur Prüfsumme passt. Nichts davon liegt öffentlich; nur das Foto eines auf Papier unterschriebenen Blatts liegt auf S3.

**Unterschrift auf dem Bildschirm** (`SignaturePad.vue`): Feld für Finger, Stift oder Maus, „Neu“ löscht. Gespeichert werden Bild (PNG als Data-URI in `loan_signatures.image`), Name, Rolle, Zeitpunkt, Konto (beim Herausgeber), Gerät (User-Agent) und die Prüfsumme des Inhalts. Der Server prüft, dass ein echtes PNG kommt. Das ist eine einfache elektronische Signatur; ob sie genügt, klärt der FSR mit dem AStA.

**Vertragstext** (`Support\ContractTemplate`): mitgeliefert in `modules/Loans/resources/contract.txt` (Leihvertrag nach §§ 598 ff. BGB, elf Paragraphen, nicht juristisch geprüft). Unter Verwaltung › Einstellungen › Verleih änderbar; gespeichert wird nur ein abweichender Text, „Zurück zum mitgelieferten Text“ löscht ihn wieder. Kleine Auszeichnung: Leerzeile trennt Absätze, `## `/`### ` Überschriften, `1. ` und `- ` Listen, `**fett**`. Variablen: `{number}`, `{lender}`, `{issuer}`, `{borrower}`, `{responsible}`, `{responsible_address}`, `{responsible_phone}`, `{responsible_email}`, `{pickup}`, `{return}`, `{purpose}`, `{contact}`, `{privacy_url}`, `{place}`, `{date}`. Der Text wird vor der Auszeichnung maskiert und kann kein HTML enthalten. Jede Buchung behält den Text ihrer Ausgabe (`loans.contract_text` und im Snapshot).

Der QR-Code zur Verleihseite kommt erst mit Phase 5 auf die Dokumente.

## Pack-PIN

Mit der Pack-PIN bestätigt jemand ohne Konto, dass er eine Box für eine Buchung kontrolliert hat (`Actions\CheckBox`, `Http\PackPinController`, Abschnitt `PublicPackPin.vue` auf der öffentlichen Box-Seite).

- Jede Buchung bekommt beim Bestätigen eine zufällige sechsstellige PIN (verschlüsselt gespeichert). Sie steht auf der Buchung und der Buchungsbestätigung; `loans.manage` würfelt neu oder legt sie fest.
- Das Feld erscheint auf `/s/{code}` nur, wenn die Box in einer bestätigten oder ausgegebenen Buchung steckt. Die PIN gilt nur für die Boxen dieser Buchung.
- Nach der richtigen PIN (in der Sitzung des Browsers gemerkt) zeigt die Seite Nummer und Zeitraum der Buchung, die bisher genutzten Namen und die Kontrollen der Box, sonst nichts.
- „Kontrolle bestätigen“ speichert Name, abgehakte Nummern und Zeilenzahl an der Position (`loan_box_checks`). Mehrere Kontrollen bleiben sichtbar, am Ausgabe-Bildschirm, auf der Buchung und auf der Materialliste.
- Nach fünf falschen PINs wartet das PIN-Feld dieser Box 15 Minuten, gezählt je Box und Adresse (`CheckBox`, Rate Limiter). Die PIN selbst wird nie gesperrt, damit niemand ohne Konto die Packenden aussperren kann. Dazu kommt das Rate Limit der Kurzlinks.

Der Vermerk ersetzt weder den Scan bei der Ausgabe noch die Unterschriften.

## Rückgabe

„Zurücknehmen“ auf der Buchung oder „Rückgabe dieser Buchung“ auf der Scan-Karte eines ausgegebenen Gegenstands (`/loans/{id}/return`, `ReturnLoan`). Der Stand liegt auf dem Server wie bei der Ausgabe.

Schritte: **Material → Übersicht → Unterschriften**. Die Übersicht zeigt, was in Ordnung zurück ist, was einen Mangel hat und was ausgegeben bleibt (Teilrückgabe), bevor jemand unterschreibt. Weiter geht es, sobald eine Position erledigt ist. „Ungeprüft annehmen“ im ersten Schritt überspringt das Zählen. Die Prüfung hat zwei Schritte: **Prüfen → Abschluss**, der Abschluss öffnet sich erst, wenn jede Position erledigt ist.

- **Scannen:** Jede offene Zeile wird abgehakt (Scan der ausgegebenen Nummer, bei Mengen die abgeteilte; von Hand mit Vermerk im Beleg). Eine abgehakte Box hakt ihren Inhalt mit ab, Mengen mit der eingetragenen Anzahl (ohne Eintrag voll); eine falsche Anzahl lässt alles offen; der letzte abgehakte Inhalt hakt die Box ab. Ausnahme: Kam die Box beim letzten Mal ungeprüft zurück, muss man den Inhalt einzeln abhaken. Bei der späteren **Prüfung** hakt die Box nichts mit ab, dort wird jede Zeile einzeln angesehen.
- **Mengen:** Man trägt die gezählte Anzahl ein, vor oder nach dem Abhaken; eine geänderte Anzahl an einer abgehakten Zeile wird sofort übernommen. Fehlt etwas, steht die Zeile auf „fehlt“, außer bei Verbrauchsmaterial: was davon nicht zurückkommt, wird beim Buchen als verbraucht ausgebucht. Fehlende Stücke eines anderen Mengenartikels werden beim Buchen als eigener Teil ohne Ort abgeteilt (wie ein fehlendes Einzelstück), der Rest kommt mit seiner Anzahl ins Bewegungsprotokoll. Was fehlt, ist danach **vermisst**, siehe unten. Ein Mangel (beschädigt, verschmutzt) an einer Mengenzeile übernimmt die eingetragene Anzahl und vermerkt die fehlenden Stücke in der Notiz.
- **Mängel:** an jeder Zeile fehlt, beschädigt, verschmutzt oder unvollständig, mit Notiz und Fotos. „Beschädigt“ ändert den Zustand mit dem Dialog aus Phase 1 und verweist auf die Buchung (`item_condition_changes.loan_id`); „verschmutzt“ und „unvollständig“ schreiben einen Eintrag mit Fotos in den Verlauf, ohne den Zustand zu ändern. Ein schon bei der Ausgabe vermerkter Mangel steht an der Zeile. Ein fehlendes Einzelstück, auch aus einer Box, hat danach keinen Ort.
- **Unterschriften:** Rückgebender (Name änderbar) und Annehmender, oder auf Papier. Eigenbedarf ohne Unterschriften.
- **Buchen:** Nur die abgehakten oder markierten Zeilen werden gebucht, mit eigenem Rückgabebeleg und Bewegung `return`. Was noch fehlt, lässt die Buchung ausgegeben (**Teilrückgabe**). Sind alle Zeilen gebucht: ohne Abweichung „abgeschlossen“, sonst „abgeschlossen mit Mängeln“; die Mängel erscheinen im Dashboard, bis `loans.cancel` sie mit Notiz abschließt.
- **Ungeprüft annehmen:** bucht alles Offene ohne einzelnes Abhaken; beide unterschreiben, der Beleg trägt „ungeprüft, Mängel vorbehalten“, die Frist stoppt. Die Buchung steht auf „angenommen, ungeprüft“ und unter „Prüfung offen“. Eine Frist für die Prüfung gibt es nicht.

## Prüfung und Einräumen

**Prüfung** (`/loans/{id}/inspect`): dieselbe Ansicht für die ungeprüft angenommenen Zeilen. Jede Zeile einzeln abhaken oder als Mangel vermerken; „alles vollständig“ gibt es hier nicht. „Prüfung abschließen“ geht erst, wenn jede Zeile erledigt ist, und schließt die Buchung ab.

**Ungeprüfte Boxen** lassen sich neu verleihen. Der Hinweis steht an der Box, in der Verfügbarkeit der neuen Buchung und auf ihrer Materialliste. Er verschwindet erst mit der Prüfung; „alles vollständig“ ist für diese Boxen auch bei der nächsten Rückgabe gesperrt. Fehlt später etwas, zeigen beide Buchungen die Zeile.

**Vermisst** (`loan_items.missing_item_id`, `ResolveMissing`): Ein fehlendes Einzelstück (auch aus einer Box) oder die als eigener Teil abgeteilten fehlenden Stücke eines Mengenartikels sind nach der Rückgabe vermisst. Sie liegen nirgends, tragen den Hinweis „Vermisst seit der Rückgabe von B-…“ (Seite, Scan-Karte, mit „Wieder da melden“) und lassen sich nicht buchen (`Availability::problemOf`). Die Buchung zeigt sie im Abschnitt „Vermisst“:
- **Wieder da** (`loans.issue`): Stücke eines Mengenartikels gehen sofort zum Bestand zurück (`MergeItem`; wartet der verliehene Teil noch aufs Einräumen, kommen sie dort dazu). Ein Einzelstück kommt auf die Liste „Noch nicht eingeräumt“ und wird wie nach einer Rückgabe eingeräumt. Ist danach nichts mehr vermisst und nichts beschädigt oder verschmutzt, schließen sich die Mängel von selbst („Alles Fehlende ist wieder da.“).
- **Mängel abschließen** (`loans.cancel`): Was dann noch vermisst ist, wird als verloren ausgemustert (Grund „verloren“, die Notiz im Vermerk); der Dialog nennt es vorher.
- Bekommt ein vermisster Gegenstand auf anderem Weg einen Ort (Umlagern) oder wird zurückgebucht, ist er nicht mehr vermisst (`LoanItem::scopeStillMissing`).

**Einräumen** (Verleih › Rückgaben, `StowItem`): Liste „Noch nicht eingeräumt“ mit Stammplatz. Gegenstand scannen, dann das Regal: Der Gegenstand wird dorthin umgelagert (der Stammplatz wird bestätigt, ein anderer Ort wird genannt). Ein abgeteilter Teil geht ohne Regal zum Ursprung zurück (`MergeItem`), mit der zurückgekommenen Menge. Fehlt von einem ganz ausgegebenen Mengenartikel etwas, wird der Rest beim Einräumen als verloren ausgebucht. Ein Gegenstand, der auf anderem Weg einen Ort bekommt, gilt als eingeräumt. Die Seite zeigt außerdem „Prüfung offen“ und „Mängel offen“.

## Am Gegenstand

Über die Erweiterungspunkte des Kerns ([MODULES.md](MODULES.md#gegenstände), `Support\LoanItemInfo`):

| Hinweis | Wo | Sperre | Aktion auf der Scan-Karte |
|---|---|---|---|
| „Ausgegeben an … bis …“ bzw. „seit … Tagen überfällig“ | Seite, Liste (Abzeichen), Scan-Karte | ja | Rückgabe dieser Buchung (`loans.issue`) |
| „Ungeprüft zurück aus …“ | Seite, Liste, Scan-Karte | nein | – |
| „Zurück aus …, noch nicht eingeräumt“ | Seite, Liste, Scan-Karte | nein | Einräumen (`loans.issue`) |
| „Gebucht in …“, „Vorgemerkt im Entwurf …“; für den Inhalt einer Box „Gebucht mit der Box … in …“ | Seite, Scan-Karte | nein | – |
| – | Scan-Karte eines verleihbaren Gegenstands | – | Zur Buchung hinzufügen (`loans.manage`) |

Die öffentliche Ansicht zeigt nichts davon (kein Entleiher, keine Buchung).

## Einstellungen

Verwaltung › Einstellungen › Verleih (`loans.settings`):

| Schlüssel | Bedeutung |
|---|---|
| `loans.lender` | Verleiher im Vertrag, Vorgabe „Studierendenschaft der FH Aachen, vertreten durch den Fachschaftsrat …“; die richtige Bezeichnung klärt der FSR mit dem AStA |
| `loans.place` | Ort der Unterschrift; leer: der Ort aus der Anschrift der Organisation |
| `loans.buffer_hours` | Stunden, die zwischen zwei Buchungen frei bleiben (0 bis 168) |
| `loans.number_prefix` | Präfix der Buchungsnummer, Vorgabe `B` |
| `loans.contract_template` | eigener Vertragstext; leer: der mitgelieferte |

Dazu die Liste der Fachschaften und eine Vorschau des Vertrags mit Beispielwerten (auch als PDF). Zeiten gelten in der Zeitzone `APP_TIMEZONE` (Vorgabe `Europe/Berlin`).

## Rechte

| Recht | Erlaubt | Freies Mitglied | FSR-Mitglied | Vorstand | Admin |
|---|---|---|---|---|---|
| `loans.view` | Buchungen, Kalender, Veranstaltungen, Reiter am Gegenstand | ja | ja | ja | ja |
| `loans.manage` | Buchungen anlegen, ändern, bestätigen, verlängern, Pack-PIN; Kontakte; Veranstaltungen ändern; Kontaktdaten sehen | – | ja | ja | ja |
| `loans.issue` | Ausgabe, Rückgabe, Prüfung, Einräumen, Liste „Rückgaben“ | – | ja | ja | ja |
| `loans.cancel` | Stornieren, Mängel abschließen | – | – | ja | ja |
| `loans.settings` | Leihvertrag, Verleiher, Puffer, Präfix, Fachschaften | – | – | ja | ja |

Die Vorgaben stehen unter `start_roles` in `module.json` und gelten bei der Einrichtung; in bestehenden Instanzen vergibt man sie in der Rollenverwaltung. Die Pack-PIN braucht kein Recht, nur die PIN.

## Datenbank

| Tabelle | Wichtige Spalten |
|---|---|
| `councils` | `number`, `name`, `is_active` |
| `contacts` | `name`, `email`, `phone`, `street`, `zip`, `city`, `council_id` (leer = privat), `notes` |
| `events` | `name`, `starts_at`, `ends_at`, `place`, `responsible_contact_id`, `notes` |
| `loans` | `number`, `status`, `kind`, `borrower_contact_id`, `responsible_contact_id`, `responsible_user_id`, `issuer_id`, `event_id`, `pickup_at`, `return_at`, `confirmed_at`, `issued_at`, `returned_at`, `inspected_at`, `defects_closed_at`, `defects_note`, `purpose`, `notes`, `pack_pin` (verschlüsselt), `pin_attempts`, `contract_text`, `cancelled_at`, `cancelled_reason`, `created_by` |
| `loan_items` | `loan_id`, `parent_id` (Zeile einer Box), `item_id`, `quantity`, `issued_item_id` (abgeteilter Teil), `condition_at_issue`, `checked_at`/`_by`/`_by_scan` (Ausgabe), `return_checked_at`/`_by`/`_by_scan` (Rückgabe oder Prüfung, noch nicht gebucht), `returned_quantity`, `return_state` (ok, missing, damaged, dirty, incomplete), `return_note`, `issued_at` und `issue_document_id` (mit welcher Materialliste die Zeile ging; leer: Rest), `issue_state` und `issue_note` (Mangel bei der Ausgabe), `returned_at`, `return_document_id`, `inspected_at`, `inspected_by`, `stowed_at` |
| `loan_documents` | `loan_id`, `type` (confirmation, contract, list, return_receipt, paper_scan), `snapshot` (JSON), `hash`, `path` (nur Papier-Scan), `created_by` |
| `loan_signatures` | `loan_id`, `loan_document_id`, `role` (responsible, issuer, returner, receiver), `name`, `image`, `signed_at`, `user_id`, `device`, `document_hash`, `on_paper` |
| `loan_box_checks` | `loan_item_id`, `name`, `ticks` (JSON), `total`, `checked_at` |

Im Kern: Bewegungsarten `issue` und `return` und der Fremdschlüssel für `item_condition_changes.loan_id`. Ob ein Gegenstand ausgegeben, ungeprüft oder noch nicht eingeräumt ist, steht nicht in einer Spalte des Kerns; der Kern fragt das Modul. Buchungen mit Ausgabe werden nie gelöscht, nur Entwürfe.

## Routen

Alle unter `/loans` hinter `auth` und `module:loans`; ausgeschaltet antworten sie mit 404.

| Bereich | Adressen |
|---|---|
| Buchungen | `/loans`, `/loans/create`, `/loans/{id}`, `/loans/{id}/edit`, `confirm`, `extend`, `cancel`, `pin`, `close-defects`, `positions`, `/loans/search-items`, `/loans/add-item/{item}` |
| Ausgabe | `/loans/{id}/issue`, `issue/check`, `issue/rows/{row}/uncheck`, `issue/rows/{row}/defect`, `issue/rows/{row}/reset-defect`, `issue/rows/{row}/drop`, `issue/responsible`, `issue/complete`, `/loans/{id}/preview/{contract,list}.pdf` |
| Rückgabe, Prüfung | `/loans/{id}/return`, `/loans/{id}/inspect`, je `check`, `rows/{row}/defect`, `rows/{row}/reset`; `return/complete`, `return/unchecked`, `inspect/complete` |
| Rückgaben, Einräumen | `/loans/returns`, `/loans/returns/stow` |
| Kurzinfo (JSON, Fenster am Kiosk) | `/loans/{id}/details` |
| Dokumente | `/loans/{id}/confirmation.pdf`, `/loans/{id}/documents/{document}` |
| Kalender | `/loans/calendar`, `/loans/calendar/loans`, `/loans/calendar/timeline`, `/loans/items/{item}`, `/loans/stats` |
| Kontakte, Veranstaltungen | `/loans/contacts…`, `/loans/events…` |
| Einstellungen | `/loans/settings`, `/loans/settings/preview.pdf`, `/loans/councils…` |
| Öffentlich (ohne Anmeldung) | `POST /s/{code}/pack-pin`, `POST /s/{code}/box-check` (Rate Limit `short-links`) |
| Kiosk (Modul Kiosk) | `/kiosk/loans`, `/kiosk/loans/find` und Ausgabe, Rückgabe, Prüfung, Einräumen, Kurzinfo, „Wieder da“ und Mängel abschließen unter `/kiosk/loans/…` aus `routes/kiosk.php`, siehe [KIOSK.md](KIOSK.md#verleih-am-kiosk) |

## Sicherheit

- Kontaktdaten nur mit `loans.manage`; die öffentliche Box-Seite zeigt vor der richtigen PIN nichts von einer Buchung, danach nur Nummer und Zeitraum.
- Pack-PIN verschlüsselt, Vergleich mit `hash_equals`, nach fünf Fehlversuchen je Box und Adresse 15 Minuten Wartezeit, Rate Limit der Kurzlinks. Die Spalte `loans.pin_attempts` wird nicht mehr genutzt.
- Unterschriften nur als geprüftes PNG bis rund 400 KB; Vertragstext maskiert, bevor er ausgezeichnet wird.
- Unterschriebene Dokumente (Vertrag, Materialliste, Rückgabebeleg, Foto des Blatts) und Unterschriftsbilder nur mit `loans.issue` oder `loans.manage` (`LoanController::canSeeDocuments()`); wer nur `loans.view` hat, sieht Positionen und Status. Die Buchungsbestätigung mit PIN nur mit `loans.manage`.
- Die Prüfsumme der Dokumente liegt neben ihrem Inhalt: Sie zeigt versehentliche Änderungen, schützt aber nicht vor jemandem mit Schreibzugriff auf die Datenbank.
- Ausgegebene Gegenstände sind gegen Umlagern und Ausmustern gesperrt; die Sperre prüfen die Actions des Kerns, nicht nur die Oberfläche.

## Code

| Was | Wo |
|---|---|
| Provider, Rechte | `modules/Loans/LoansServiceProvider.php`, `module.json` |
| Vorgänge | `modules/Loans/Actions` (`SaveLoan`, `ChangePositions`, `ConfirmLoan`, `CancelLoan`, `ExtendLoan`, `IssueLoan`, `ReturnLoan`, `StowItem`, `CheckBox`) |
| Verfügbarkeit, Dokumente, Vertrag | `modules/Loans/Support` |
| Seiten und Komponenten | `modules/Loans/resources/js` (Startseite am Kiosk: `pages/Kiosk.vue`, `Http/KioskLoanController.php`) |
| PDFs | `modules/Loans/resources/views/pdf` |
| Demo-Daten | `modules/Loans/Support/LoansDemo.php`, siehe [DEMO.md](DEMO.md) |
| Tests | `modules/Loans/tests` (Basisklasse `LoansTestCase`) |
