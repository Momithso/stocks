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
   MAIL_HOST=...
   MAIL_USERNAME=...
   MAIL_PASSWORD=...
   MAIL_FROM_ADDRESS=stocks@example.org
   WEB_PORT=8000                 # je Instanz ein eigener Port hinter dem Reverse Proxy
   ```

3. `docker compose up -d`. Beim Start laufen die Migrationen und `stocks:sync` von selbst.
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

## Umgebungsvariablen von Stocks

| Variable | Bedeutung |
|---|---|
| `SETUP_TOKEN` | Schlüssel für den Einrichtungsassistenten; ohne ihn ist der Assistent aus |
| `CHECK_LEAKED_PASSWORDS` | `true` lehnt Passwörter aus bekannten Datenlecks ab (Abfrage bei haveibeenpwned.com) |
| `APP_FORCE_HTTPS` | Links immer mit https erzeugen (hinter einem Reverse Proxy) |

Alle anderen Variablen sind die von Laravel und Octane, siehe `.env.example`.

## Lokale Entwicklung

```bash
cp .env.example .env
composer install && npm install
php artisan key:generate
php artisan migrate:fresh --seed   # Demo-Instanz, alle Konten mit Passwort "correct horse battery", PIN 482915
npm run dev
php artisan serve
```

Demo-Konten: `admin@example.com` (Admin), `demo1@example.com` (Vorstand), `demo2@example.com` (FSR-Mitglied), `demo3@example.com` (Freies Mitglied). Das Beispielmodul ist eingeschaltet.
