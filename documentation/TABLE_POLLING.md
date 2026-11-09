# Tabellen: seitenweises Laden, Server-Suche, Server-Sortierung und Live-Update

Alle Tabellen in Stocks laden ihre Daten seitenweise beim Scrollen (Infinite Scroll). Suche und Sortierung laufen auf dem Server, und offene Tabellen aktualisieren sich live, indem sie regelmäßig nur die **Änderungen** abfragen. Ziel ist, die Ladezeiten und die übertragene Datenmenge zu minimieren.

Diese Dokumentation beschreibt, wie das System aufgebaut ist, wie das Polling funktioniert und wie man neue Tabellen hinzufügt.

## Inhalt

- [Überblick](#überblick)
- [Request-Parameter](#request-parameter)
- [Backend: die Klasse `Table`](#backend-die-klasse-table)
- [Live-Update (Polling)](#live-update-polling)
- [Frontend: `UiTable`](#frontend-uitable)
- [Fallstricke](#fallstricke)
- [Vorhandene Tabellen](#vorhandene-tabellen)

## Überblick

```
Browser                                        Server
───────                                        ──────
Seite öffnen  ─────────── GET /seite ────────▶ Controller
                                               └─ new XyzTable($request)->scroll()
              ◀── Inertia-Seite mit 50 Zeilen ─   (Seite 1, sortiert, gesucht)

Nach unten scrollen ───── GET /seite?page=2 ──▶ gleicher Controller (Teil-Request,
              ◀── nächste 50 Zeilen ────────────  nur die Tabellen-Prop wird berechnet)

Spalte / Suche ────── GET /seite?sort=…&search=… (Reset auf Seite 1)

alle 2,5 s  ───── GET /seite?changes_since=…&loaded_ids=… ──▶ $table->changesResponse()
              ◀── {"time":…,"rows":[…],"removed_ids":[…],"new_ids":[…]} ──
```

Bausteine:

| Teil | Datei | Aufgabe |
|---|---|---|
| Basisklasse | [`app/Support/Table/Table.php`](../app/Support/Table/Table.php) | Suche, Sortierung, Seiten, Live-Update |
| Tabellen-Klassen | `app/Support/Table/*Table.php` | Beschreiben je eine Tabelle: Abfrage, Zeilen, Suchfelder, Sortierschlüssel |
| Tabellenkomponente | [`resources/js/components/ui/Table.vue`](../resources/js/components/ui/Table.vue) (`UiTable`) | Darstellung, Infinite Scroll, Sortierung, Suche, Live-Update |
| Spalten / Sortierwerte | `resources/js/types/table-column.ts`, `table-sort-value.ts` | Spaltendefinition mit `sortKey` |
| Polling | [`resources/js/composables/usePolling.ts`](../resources/js/composables/usePolling.ts) | Periodische Abfragen, pausiert bei unsichtbarem Tab |
| Startzeit | `serverTime` in [`HandleInertiaRequests`](../app/Http/Middleware/HandleInertiaRequests.php) | Startpunkt des Live-Updates |

Technisch basiert das Nachladen auf den eingebauten Funktionen von Inertia v3: `Inertia::scroll()` im Backend und `<InfiniteScroll>` im Frontend. Es werden keine zusätzlichen Pakete benötigt.

## Request-Parameter

Alle Parameter stehen in der URL der Seite. Dadurch übernimmt Inertia sie beim Nachladen automatisch, und Suche und Sortierung überstehen ein Neuladen der Seite.

| Parameter | Bedeutung |
|---|---|
| `page` | Seite des Infinite Scroll (wird von Inertia gesetzt, nicht in der URL angezeigt) |
| `search` | Suchbegriffe. Mehrere Wörter: jedes Wort muss in mindestens einem Suchfeld vorkommen |
| `sort` | Sortierschlüssel aus `sorts()`. Unbekannte Schlüssel werden ignoriert |
| `direction` | `asc` (Standard) oder `desc` |
| `changes_since` | Unix-Zeitstempel. Ist der Parameter gesetzt, liefert der Controller das Live-Update als JSON statt der Seite |
| `loaded_ids` | Kommagetrennte IDs der geladenen Zeilen (für das Live-Update) |

Weitere tabellenspezifische Filter sind möglich, siehe `applyFilters()`.

## Backend: die Klasse `Table`

Eine Tabelle ist eine Klasse, die von `App\Helpers\Table\Table` erbt. Pflicht sind nur zwei Methoden, alles andere ist optional:

| Methode | Pflicht | Zweck |
|---|---|---|
| `query(): Builder` | ja | Basis-Abfrage mit dem Bereich der Tabelle (z. B. „Konten mit Rollen“) inklusive `with()` / `withCount()` |
| `row(Model $model): array` | ja | Welche Felder einer Zeile an das Frontend gehen. Nur das Nötige ausliefern! |
| `searchColumns(): array` | nein | Suchfelder, z. B. `['firstname', 'email']`, Relationen als `'user.firstname'` |
| `sorts(): array` | nein | Sortierschlüssel → Spalte (String) oder Closure |
| `applyDefaultSort(Builder)` | nein | Sortierung, wenn keine gewählt ist |
| `applyFilters(Builder)` | nein | Zusätzliche Filter aus dem Request |
| `perPage(): int` | nein | Zeilen pro Seite (Standard `50`) |
| `refreshesAllRows(): bool` | nein | Live-Update liefert immer alle geladenen Zeilen (für Zählwerte, siehe unten) |
| `whereChangedSince(Builder, Carbon)` | nein | Was als „geändert“ gilt (Standard: `updated_at`) |
| `rowVersion(Model): int` | nein | Version einer Zeile (Standard: `updated_at`) |

Öffentliche Methoden für den Controller:

| Methode | Rückgabe |
|---|---|
| `scroll()` | `Inertia::scroll(...)`-Prop mit der aktuellen Seite |
| `wantsChanges()` | `true`, wenn der Request das Live-Update anfragt (`changes_since`) |
| `changesResponse()` | JSON-Antwort des Live-Updates |
| `changes()` | Das Live-Update als Array (z. B. für Tests) |
| `currentSort()` | `[sortKey|null, 'asc'|'desc']` |

### Was intern passiert

```php
// Abfrage mit Bereich, Filtern und Suche (für Seiten UND Live-Update)
protected function filteredQuery(): Builder
{
    $query = $this->query();
    $this->applyFilters($query);
    $this->applySearch($query);

    return $query;
}

// zusätzlich sortiert; der Primärschlüssel kommt immer zuletzt dazu,
// damit die Reihenfolge über Seitengrenzen stabil ist (keine doppelten/fehlenden Zeilen)
protected function sortedQuery(): Builder
{
    $query = $this->filteredQuery();
    // ... Sortierschlüssel aus sorts() oder applyDefaultSort()

    return $query->orderBy($query->getModel()->getQualifiedKeyName());
}

public function scroll(): ScrollProp
{
    return Inertia::scroll(fn () => $this->sortedQuery()
        ->simplePaginate($this->perPage())          // ohne zusätzliche COUNT-Abfrage
        ->through(fn (Model $model) => $this->toRow($model)));
}
```

- **`simplePaginate`** statt `paginate`: Laravel spart die `COUNT(*)`-Abfrage über die ganze Tabelle. Für Infinite Scroll reicht „gibt es eine nächste Seite?“.
- **Die Suche** nutzt `whereLike()`. Groß-/Kleinschreibung wird auf MySQL/MariaDB, PostgreSQL und SQLite gleich ignoriert.
- **`toRow()`** hängt an jede Zeile das Feld `_version` an (siehe [Versionen](#versionen-welche-zeile-gewinnt)).

### Im Controller verwenden

Der gleiche Controller liefert die Seite, die nachgeladenen Seiten und das Live-Update. So gelten automatisch dieselbe Middleware und dieselben Berechtigungen wie für die Seite:

```php
public function index(Request $request): Response|JsonResponse
{
    $usersTable = new UsersTable($request);
    if ($usersTable->wantsChanges()) {
        return $usersTable->changesResponse();
    }

    return Inertia::render('Admin/Users/Index', [
        'roles' => fn () => Role::all(),
        'users' => $usersTable->scroll(),
    ]);
}
```

Beim Nachladen schickt Inertia einen Teil-Request (nur die Prop `users`). Andere Props sollten deshalb als Closure übergeben werden, wenn sie teuer sind, z. B. `'roles' => fn () => Role::all()`. Dann werden sie beim Nachladen nicht berechnet.

## Live-Update (Polling)

### Ablauf

1. Beim Laden der Seite liefert der Server die geteilte Prop **`serverTime`** (Unix-Zeitstempel). Das ist der Startpunkt.
2. `UiTable` fragt alle **2,5 Sekunden** (`liveInterval`) die URL der Seite mit `changes_since` und `loaded_ids` ab. Ist der Browser-Tab ausgeblendet, pausiert das Polling und läuft beim Zurückkehren sofort einmal.
3. Der Server antwortet mit `time`. Das wird der Startpunkt für die nächste Abfrage.

Beispiel einer Abfrage:

```
GET /admin/users?sort=lastname&changes_since=1791749634&loaded_ids=2401,2402,2403
Accept: application/json
```

Antwort ohne Änderungen (59 Bytes statt der kompletten Liste):

```json
{ "time": 1791749639, "rows": [], "removed_ids": [], "new_ids": [] }
```

Antwort mit Änderungen:

```json
{
  "time": 1791749642,
  "rows": [
    { "id": 2401, "is_present": 1, "user": { "id": 17, "firstname": "Max", "lastname": "Harber" }, "_version": 1791749641 }
  ],
  "removed_ids": [2403],
  "new_ids": [2650]
}
```

### Was der Server berechnet

```php
public function changes(): array
{
    // die Zeit wird VOR den Abfragen genommen, damit keine Änderung verloren geht
    $time = now()->timestamp;
    $since = Carbon::createFromTimestamp((int) $this->request->query('changes_since'), config('app.timezone'));
    $loadedIds = /* IDs aus loaded_ids, max. 2000 */;

    // geladene Zeilen, die es (mit Suche und Filtern) noch gibt
    $existingIds = $this->filteredQuery()->whereIn($key, $loadedIds)->pluck($key);

    // davon die geänderten (oder alle bei refreshesAllRows())
    $changedQuery = $this->filteredQuery()->whereIn($key, $existingIds);
    if (! $this->refreshesAllRows()) {
        $changedQuery->where(fn ($query) => $this->whereChangedSince($query, $since));
    }

    // neu angelegte Zeilen, die noch nicht geladen sind
    $newIds = $this->filteredQuery()->whereNotIn($key, $loadedIds)
        ->where('created_at', '>=', $since)->limit(100)->pluck($key);

    return [
        'time' => $time,
        'rows' => /* geänderte Zeilen über row() */,
        'removed_ids' => array_diff($loadedIds, $existingIds),
        'new_ids' => $newIds,
    ];
}
```

| Feld | Bedeutung | Reaktion der Tabelle |
|---|---|---|
| `rows` | Geladene Zeilen, die sich seit `changes_since` geändert haben | Zeile wird an Ort und Stelle aktualisiert (nicht neu einsortiert) |
| `removed_ids` | Geladene Zeilen, die es nicht mehr gibt oder die nicht mehr zur Suche bzw. zum Bereich passen | Zeile wird ausgeblendet |
| `new_ids` | Neue Zeilen, die noch nicht geladen sind | Hinweis „Es gibt X neue Einträge. Aktualisieren“ |

Warum Löschungen über `loaded_ids` erkannt werden und nicht über das Audit-Log: Das Audit-Log lässt sich abschalten (`AUDITING_ENABLED`), und es erfasst keine Löschungen per Foreign Key (`ON DELETE CASCADE`). Löscht man z. B. einen User, verschwinden seine Anmeldungen ohne eigenes Audit. Der Abgleich der geladenen IDs erkennt das immer.

### Was als „geändert“ gilt

Standard ist `updated_at >= changes_since`. Hängt eine Zeile von anderen Tabellen ab, muss `whereChangedSince()` erweitert werden. Beispiel Anmeldungen: Der Name kommt vom User. Ändert sich der User, soll die Anmeldung trotzdem aktualisiert werden:

```php
// Beispiel: Zeile hängt von einer anderen Tabelle ab
protected function whereChangedSince(Builder $query, Carbon $since): void
{
    $query->where('registrations.updated_at', '>=', $since)
        ->orWhereHas('user', fn (Builder $userQuery) => $userQuery->where('users.updated_at', '>=', $since));
}
```

Änderungen, die `updated_at` nicht verändern, werden nicht erkannt. Beispiel: Rollen über `syncRoles()` ändern nur die Pivot-Tabelle. Deshalb wird der User danach „angefasst“:

```php
// DashboardAdminController::editUser
$user->syncRoles($roles);

// role changes do not change the user itself, touch it for the live update of the user table
$user->touch();
```

### Zählwerte: `refreshesAllRows()`

Kleine Tabellen mit berechneten Werten (z. B. eine Anzahl per `withCount()`) ändern sich, ohne dass sich die Zeile selbst ändert. Für sie liefert das Live-Update immer alle geladenen Zeilen. Das lohnt sich nur bei kleinen Tabellen:

```php
// Beispiel einer kleinen Tabelle mit Zählwerten
protected function refreshesAllRows(): bool
{
    return true;
}
```

### Versionen: welche Zeile gewinnt

Eine Zeile kann mehrfach im Browser ankommen: über eine Seite, ein Live-Update, eine eigene Aktion oder nach einem Neuladen. Jede Zeile hat deshalb ein Feld `_version`, Standard ist `updated_at` als Zeitstempel. Die Tabelle zeigt immer die Version mit dem höchsten Wert. Bei `refreshesAllRows()` ist die Version der Abfragezeitpunkt.

Bei abhängigen Daten nimmt man das Maximum:

```php
// Beispiel: Zeile hängt von einer anderen Tabelle ab
protected function rowVersion(Model $model): int
{
    return max((int) $model->updated_at?->timestamp, (int) $model->user?->updated_at?->timestamp);
}
```

### Gleiche Sekunde

Zeitstempel haben Sekundengenauigkeit. Damit keine Änderung verloren geht, die in derselben Sekunde wie die letzte Abfrage passiert ist, vergleicht der Server mit `>=`. Eine Zeile kann deshalb zweimal als geändert gemeldet werden. Das ist harmlos, die Aktualisierung ist idempotent.

### Kosten

Pro Abfrage laufen höchstens drei schlanke Datenbankabfragen über die geladenen IDs bzw. die neuen Zeilen. Übertragen werden nur geänderte Zeilen. Ohne Änderungen sind das ca. 60 Bytes statt der kompletten Tabelle. Beispiel User-Verwaltung: vorher ca. 410 KB alle 2,5 Sekunden.

## Frontend: `UiTable`

`UiTable` ist die einzige Tabellenkomponente. Sie liest die Zeilen selbst aus der Inertia-Prop, deren Name in `data` steht.

| Prop | Typ | Standard | Bedeutung |
|---|---|---|---|
| `data` | `string` | Pflicht | Name der Scroll-Prop der Seite, z. B. `"users"` |
| `columns` | `TableColumn[]` | Pflicht | Spalten: `name`, `header`, optional `value(row)` und `sortKey` |
| `idFunction` | `Function` | `(row) => row.id` | ID einer Zeile |
| `rowClass` | `Function` | `() => ({})` | CSS-Klassen pro Zeile |
| `searchable` | `boolean` | `false` | Suchfeld anzeigen |
| `live` | `boolean` | `true` | Live-Update ein/aus |
| `liveInterval` | `number` | `2500` | Intervall des Live-Updates in Millisekunden |

Zellen zeigen `value(row)` als Text. Alles andere (Badges, Links, Datum) kommt über den Slot `cell-<name>`, Buttons pro Zeile über den Slot `actions`. Anders als in Portals wird nichts per `v-html` gerendert, Benutzereingaben müssen deshalb nicht von Hand maskiert werden.

```vue
<UiTable ref="table" data="users" :columns="columns" searchable>
  <template #cell-roles="{ row }">
    <UiBadge v-for="role in row.roles" :key="role.id">{{ role.name }}</UiBadge>
  </template>
  <template #actions="{ row }">
    <UiButton size="sm" @click="edit(row)">Bearbeiten</UiButton>
  </template>
</UiTable>
```

Über `ref` erreichbar: `patchRow(id, fields)`, `removeRow(id)`, `refresh()` (Live-Update sofort), `reload(changes?)` und `rows`.

Im Controller liefert dieselbe Route die Seite, das Nachladen und das Live-Update:

```php
$table = new UsersTable($request);
if ($table->wantsChanges()) {
    return $table->changesResponse();
}

return Inertia::render('Admin/Users/Index', ['users' => $table->scroll()]);
```

## Fallstricke

- **Kein Flex-Container um den horizontalen Scroll-Bereich.** Inertia sucht für das Nachladen den nächsten scrollbaren Vorfahren; ein `overflow-x-auto`-Element in einem `flex flex-col` wird fälschlich als vertikaler Scroll-Bereich erkannt und lädt sofort alle Seiten.
- **Teure Props als Closure übergeben**, sonst werden sie bei jedem Nachladen berechnet.
- **`updated_at` muss sich ändern.** Pivot-Änderungen (z. B. `syncRoles()`) ändern die Zeile nicht; danach `touch()` aufrufen.
- **Eine Tabelle pro Seite.** Die Parameter `page`, `search`, `sort` und `direction` sind nicht pro Tabelle getrennt.
- **Maximal 2000 geladene IDs** werden beim Live-Update geprüft.

## Vorhandene Tabellen

| Klasse | Seite | Prop | Suche | Live-Update |
|---|---|---|---|---|
| `UsersTable` | Verwaltung → Konten | `users` | Vorname, Nachname, E-Mail | geänderte Zeilen |
