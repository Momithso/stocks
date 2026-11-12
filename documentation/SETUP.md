# Neue Instanz aufsetzen

Jede Organisation bekommt eine eigene Instanz mit eigener Datenbank. Alle Instanzen nutzen dasselbe Docker-Image; was sich unterscheidet, steht in Umgebungsvariablen und in den Einstellungen.

## Mit Docker Compose

1. `docker-compose.yaml` in einen eigenen Ordner je Instanz kopieren.
2. Daneben eine `.env` anlegen:

   ```env
   APP_KEY=base64:...            # php artisan key:generate --show
   APP_URL=https://stocks.example.org
   SETUP_TOKEN=...               # langer Zufallswert, nur für die Einrichtung
   DB_PASSWORD=...
   REDIS_PASSWORD=...
   MAIL_FROM_ADDRESS=stocks@example.org   # Vorgabe; Server und Absender trägt man unter Einstellungen › E-Mail ein
   AWS_ACCESS_KEY_ID=...         # Zugang zum mitgestarteten MinIO für Fotos
   AWS_SECRET_ACCESS_KEY=...
   WEB_PORT=8000                 # je Instanz ein eigener Port hinter dem Reverse Proxy
   VAPID_PUBLIC_KEY=...          # Push, einmal erzeugt mit „php artisan webpush:vapid --show“; leer: nur Mails
   VAPID_PRIVATE_KEY=...
   ```

3. `docker compose up -d`. Beim Start laufen die Migrationen und `stocks:sync` von selbst. Neben `web` laufen aus demselben Image `queue` (verschickt Mails und Push) und `scheduler` (Erinnerungen alle 15 Minuten); ohne sie gehen keine Benachrichtigungen raus.
   Stocks muss über **HTTPS** erreichbar sein (Reverse Proxy), sonst gibt es keinen Push; der Server muss die Push-Dienste erreichen (siehe [NOTIFICATIONS.md](NOTIFICATIONS.md#push-einrichten)).
4. Einrichten, entweder im Browser unter `/setup` mit dem `SETUP_TOKEN` oder ohne Browser:

   ```bash
   docker compose exec web php artisan stocks:install \
     --name="Fachschaftsrat Elektrotechnik" --short-name="FSR 5" \
     --admin-firstname=Ada --admin-lastname=Admin --admin-email=ada@example.org
   ```

   Ohne `--admin-password` fragt der Befehl nach dem Passwort. Er lässt sich gefahrlos erneut ausführen.

Danach ist `/setup` gesperrt. Den `SETUP_TOKEN` kann man wieder entfernen.

## Update

1. Datenbank sichern (`mariadb-dump`).
2. `docker compose pull && docker compose up -d`. Migrationen und `stocks:sync` laufen beim Start.

**Update auf Phase 4:** Der Kiosk ist jetzt ein Modul und nach dem Update ausgeschaltet. Wer Tablets nutzt, schaltet unter Verwaltung › Module „Kiosk“ ein; gekoppelte Geräte, PINs und Protokolleinträge bleiben erhalten. Rechte `kiosk.use` und `kiosk.devices.manage` behalten ihre Rollen. Danach unter Verwaltung › Einstellungen › Kiosk-Geräte die Plattform jedes Geräts wählen ([KIOSK.md](KIOSK.md)).

**Update auf Phase 5:** Die Compose-Datei hat zwei neue Dienste (`queue`, `scheduler`) und `QUEUE_CONNECTION=redis`; die neue `docker-compose.yaml` übernehmen. Für Push die VAPID-Schlüssel erzeugen (`docker compose run --rm web php artisan webpush:vapid --show`) und in die `.env` eintragen. Unter Verwaltung › Einstellungen › Allgemein die zentrale Telefonnummer eintragen (Feld „Telefon“, erscheint auf der Verleihseite) und unter › Verleih Abholort und Erinnerungen prüfen. Bestätigte Buchungen haben sofort eine Verleihseite; Mails gehen erst bei neuen Ereignissen raus. **`APP_KEY` nicht ändern:** die Links der Verleihseiten hängen daran, auch die QR-Codes auf gedruckten Verträgen und Listen; ein neuer Schlüssel macht sie alle ungültig.

## Umgebungsvariablen von Stocks

| Variable | Bedeutung |
|---|---|
| `SETUP_TOKEN` | Schlüssel für den Einrichtungsassistenten; ohne ihn ist der Assistent aus |
| `CHECK_LEAKED_PASSWORDS` | `true` lehnt Passwörter aus bekannten Datenlecks ab (Abfrage bei haveibeenpwned.com) |
| `APP_FORCE_HTTPS` | Links immer mit https erzeugen (hinter einem Reverse Proxy) |
| `APP_TIMEZONE` | Zeitzone, in der Zeiten eingegeben, gespeichert und gedruckt werden (Abholung, Rückgabe); Vorgabe `Europe/Berlin` |
| `AWS_*` | S3-Speicher für Fotos, standardmäßig das mitgestartete MinIO; siehe [INVENTORY.md](INVENTORY.md#fotos-auf-s3) |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` | Schlüssel für Web Push; ohne sie ist Push aus, siehe [NOTIFICATIONS.md](NOTIFICATIONS.md) |
| `QUEUE_CONNECTION` | `redis` mit dem Dienst `queue`; `sync` verschickt sofort im Request (nur Entwicklung) |

Alle anderen Variablen sind die von Laravel und Octane, siehe `.env.example`.

## Lokale Entwicklung

```bash
cp .env.example .env
composer install && npm install
php artisan key:generate
php artisan migrate:fresh --seed   # Demo-Daten aus dem Generator, siehe DEMO.md
npm run dev
php artisan serve
```

Demo-Konten: `admin@example.com` (Admin), `demo1@example.com` (Vorstand), `demo2@example.com` (FSR-Mitglied), `demo3@example.com` (Freies Mitglied), alle mit Passwort „correct horse battery“ und PIN 482915. Alle Module außer dem Beispielmodul sind eingeschaltet; Inventar, Buchungen und das Blatt mit Testetiketten beschreibt [DEMO.md](DEMO.md).

### HTTPS im Entwicklungsbetrieb (Handy im WLAN)

Kamera, Bluetooth und USB gehen im Browser nur über HTTPS. Für Tests mit dem Handy im selben Netz gibt es einen HTTPS-Modus: Der Vite-Dev-Server antwortet mit HTTPS und reicht alles, was keine Datei der Oberfläche ist, an `php artisan serve` weiter. So läuft alles unter einer Adresse.

```env
DEV_HTTPS=true
APP_URL=https://192.168.178.155:5173   # IP des Rechners im WLAN
APP_FORCE_HTTPS=true
```

```bash
php artisan config:clear
php artisan serve            # Port 8000, nur lokal
npm run dev                  # https://<IP>:5173
```

Beim ersten Start legt `vite.config.js` mit `openssl` ein selbst signiertes Zertifikat in `storage/dev-https/` an (gültig für `localhost` und alle IPv4-Adressen des Rechners; nach einem IP-Wechsel den Ordner löschen). Der Browser warnt einmal; nach „Trotzdem fortfahren“ funktionieren Kamera und Direktdruck. Ohne Warnung geht es mit einem Zertifikat von `mkcert`, dessen Stammzertifikat man auf dem Handy installiert: `DEV_HTTPS_CERT` und `DEV_HTTPS_KEY` zeigen auf die Dateien. Läuft Laravel woanders als auf `http://127.0.0.1:8000`, setzt man `DEV_HTTPS_TARGET`.

Nur für die Entwicklung; eine Instanz im Betrieb steht hinter einem Reverse Proxy mit echtem Zertifikat.

Fotos brauchen S3. Mit Sail startet MinIO mit (Konsole auf http://localhost:9001), die passenden Werte stehen in `.env.example`.
