# Stocks

Inventar- und Verleihsoftware für Fachschaften: was besitzen wir, wo liegt es, wer hat es gerade.

Stocks baut auf der Struktur von [Portals](https://github.com/fsr5-fhaachen/portals) auf: Laravel 13, PHP 8.5, Inertia 3, Vue 3 mit TypeScript, Tailwind und Octane mit Swoole.

> **Stand:** Phase 0 (Fundament). Anmeldung, Konten, Rollen, Module, Einstellungen, Einrichtung und das Kiosk-Gerüst sind fertig. Inventar und Verleih folgen ab Phase 1.

## Schnellstart

```bash
cp .env.example .env
composer install && npm install
php artisan key:generate
php artisan migrate:fresh --seed
npm run dev
php artisan serve
```

Anmelden mit `admin@example.com` und dem Passwort `correct horse battery`.

## Dokumentation

| Datei                                                            | Inhalt                                                             |
| ---------------------------------------------------------------- | ------------------------------------------------------------------ |
| [documentation/CORE.md](documentation/CORE.md)                   | Aufbau, Anmeldung, Rollen und Rechte, Kiosk, Oberfläche, Datenbank |
| [documentation/INVENTORY.md](documentation/INVENTORY.md)         | Standorte, Gegenstände, Boxen, Nummern, Umlagern, Fotos            |
| [documentation/LABELS.md](documentation/LABELS.md)               | Etiketten, Vorlagen, Drucker, Kurzlinks, Scannen, Web-App          |
| [documentation/INTERFACES.md](documentation/INTERFACES.md)       | Welche Vorgänge auf welcher Oberfläche (PC, Handy, Kiosk) fehlen   |
| [documentation/MODULES.md](documentation/MODULES.md)             | Module bauen, Erweiterungspunkte                                   |
| [documentation/SETUP.md](documentation/SETUP.md)                 | Instanz aufsetzen und aktualisieren                                |
| [documentation/TABLE_POLLING.md](documentation/TABLE_POLLING.md) | Tabellen mit seitenweisem Laden und Live-Update                    |
| [documentation/DECISIONS.md](documentation/DECISIONS.md)         | Entscheidungsprotokoll                                             |
| [CONTRIBUTING.md](CONTRIBUTING.md)                               | Branches, Commits, Werkzeuge                                       |

## Tests

```bash
php artisan test --compact
vendor/bin/pint
npm run lint
```

## Lizenz

MIT, siehe [LICENSE](LICENSE). Portals © FH-Aachen FSR5.
