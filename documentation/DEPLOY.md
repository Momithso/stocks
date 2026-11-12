# Dev-Instanz über GitLab CI/CD

Die Dev-Instanz läuft unter **https://stocks-dev.proxy.sntg.eu**. Jeder Push auf `dev` baut das Image und spielt es ein. Die Pipeline steht in `.gitlab-ci.yml`.

```
GitHub (dev) ──Pull-Mirror──▶ GitLab ──Pipeline──▶ Runner auf dem Dev-Server
                                                     ├─ build:  docker build, push ins GitLab-Registry
                                                     └─ deploy: docker compose up in /opt/stocks-dev
Reverse Proxy (anderer Host) ──NetBird──▶ Dev-Server:8000 (web)
```

- Der Runner läuft **auf dem Dev-Server** und verbindet sich nur ausgehend mit GitLab. Der Server braucht keinen SSH-Zugang von außen.
- Jedes Image trägt den Commit (`:<sha>`), dazu `:dev`. **Zurückrollen:** In GitLab unter *Operate › Environments › dev* einen älteren Deploy neu starten. Er setzt genau das Image seines Commits.
- Migrationen und `stocks:sync` laufen beim Start von `web` wie in jeder Instanz ([SETUP.md](SETUP.md)).
- `deploy/dev/docker-compose.override.yaml` schließt die Ports von MariaDB und MinIO. Von außen ist nur `web` auf Port 8000 erreichbar.

## Einmalig einrichten

### 1. Spiegel von GitHub

In GitLab ein Projekt anlegen, dann *Settings › Repository › Mirroring repositories*:

- URL `https://github.com/fsr5-fhaachen/stocks.git`, Richtung **Pull**. Das Repository ist öffentlich, ein Passwort braucht es nicht.
- **„Trigger pipelines for mirror updates“** einschalten.
- `dev` unter *Settings › Repository › Protected branches* schützen. Sonst bekommt die Pipeline die geschützte Variable aus Schritt 3 nicht.

Der Pull-Mirror braucht eine Premium- oder Ultimate-Lizenz. Eine Enterprise Edition ohne Lizenz kann ihn nicht.

### 2. Runner auf dem Dev-Server

Voraussetzungen: Docker mit Compose **2.24 oder neuer** (`docker compose version`), NetBird verbunden.

```bash
# GitLab Runner aus dem Paket-Repository von GitLab installieren
curl -L https://packages.gitlab.com/install/repositories/runner/gitlab-runner/script.deb.sh | sudo bash
sudo apt install gitlab-runner

# darf Docker benutzen und ins Deploy-Verzeichnis schreiben
sudo usermod -aG docker gitlab-runner
sudo mkdir -p /opt/stocks-dev
sudo chown gitlab-runner: /opt/stocks-dev
```

In GitLab unter *Settings › CI/CD › Runners › New project runner* einen Runner anlegen:

- Tag `stocks-dev`, „Run untagged jobs“ aus
- den angezeigten Token auf dem Server eintragen:

```bash
sudo gitlab-runner register --url https://<euer-gitlab> --token <token> --executor shell
sudo systemctl restart gitlab-runner
```

Das Registry muss in GitLab eingeschaltet sein (*Settings › General › Visibility › Container registry*). Die Anmeldung übernimmt die Pipeline mit dem Job-Token.

### 3. Umgebung als CI/CD-Variable

Unter *Settings › CI/CD › Variables* eine Variable anlegen:

- Key `STOCKS_DEV_ENV`, Typ **File**, **Protected**, Environment `dev`
- Inhalt wie die `.env` aus [SETUP.md](SETUP.md), mit diesen Werten:

```env
APP_KEY=base64:...                       # einmal erzeugen und nie ändern (Links der Verleihseiten)
APP_URL=https://stocks-dev.proxy.sntg.eu
APP_ENV=production
SETUP_TOKEN=...
DB_PASSWORD=...
REDIS_PASSWORD=...
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
MAIL_FROM_ADDRESS=...
WEB_PORT=8000
VAPID_PUBLIC_KEY=...                     # für den Push-Test, siehe NOTIFICATIONS.md
VAPID_PRIVATE_KEY=...
```

`STOCKS_IMAGE` gehört nicht hinein, das setzt die Pipeline. Die Pipeline schreibt die Datei bei jedem Deploy nach `/opt/stocks-dev/.env`. Änderungen also immer in GitLab machen und danach den Deploy neu starten.

### 4. Reverse Proxy

Auf dem Proxy-Host `stocks-dev.proxy.sntg.eu` auf `http://<NetBird-IP des Dev-Servers>:8000` leiten, mit TLS am Proxy. `APP_FORCE_HTTPS` (Vorgabe `true`) sorgt dafür, dass Stocks alle Links mit https erzeugt. Ohne HTTPS gibt es keinen Push.

### 5. Erster Start

Auf `dev` pushen oder in GitLab *Build › Pipelines › Run pipeline* für `dev` starten. Danach einrichten, entweder im Browser unter `/setup` oder so:

```bash
cd /opt/stocks-dev
sudo -u gitlab-runner docker compose exec web php artisan stocks:install --name="..." --short-name="..." \
  --admin-firstname=... --admin-lastname=... --admin-email=...
```

## Im Betrieb

| Aufgabe | Befehl in `/opt/stocks-dev` |
|---|---|
| Logs | `docker compose logs -f web queue scheduler` |
| Artisan | `docker compose exec web php artisan …` |
| Demo-Daten (löscht alles) | `docker compose exec web php artisan migrate:fresh --seed --force` |
| Datenbank sichern | `docker compose exec mariadb sh -c 'mariadb-dump -u stocks -p"$MARIADB_PASSWORD" stocks' > backup.sql` |
| MariaDB, Redis, MinIO aktualisieren | `docker compose pull mariadb redis minio && docker compose up -d` (vorher sichern) |

Die Pipeline holt nur das eigene Image. Die Fremd-Images werden bewusst nur von Hand aktualisiert, damit ein Deploy nie unbemerkt die Datenbank-Version wechselt.

Mit `APP_ENV=production` fragt Laravel vor `migrate:fresh` nach, deshalb steht oben `--force`.
