<template>
  <div class="flex flex-col">
    <div v-if="searchable" class="border-b border-line p-4 sm:px-6">
      <UiInput
        v-model="searchForm.search"
        label="Suche"
        type="search"
        placeholder="Name oder E-Mail"
      />
    </div>

    <div v-if="newIds.size > 0" class="border-b border-line p-4 sm:px-6">
      <UiAlert type="info">
        {{
          newIds.size === 1
            ? "Es gibt einen neuen Eintrag."
            : `Es gibt ${newIds.size} neue Einträge.`
        }}
        <button
          type="button"
          class="ml-1 font-bold underline"
          @click="reload()"
        >
          Aktualisieren
        </button>
      </UiAlert>
    </div>

    <!-- no flex container around the scroll container, otherwise infinite scroll detects it as scrollable parent -->
    <div>
      <div class="overflow-x-auto">
        <InfiniteScroll
          :data="data"
          :items-element="`#${tbodyId}`"
          preserve-url
          only-next
        >
          <table class="min-w-full divide-y divide-line text-sm">
            <thead class="bg-surface-muted">
              <tr>
                <th
                  v-for="column in columns"
                  :key="column.name"
                  scope="col"
                  class="whitespace-nowrap px-4 py-3 text-left font-semibold sm:px-6"
                  :aria-sort="ariaSort(column)"
                >
                  <button
                    v-if="column.sortKey"
                    type="button"
                    class="inline-flex items-center gap-2 hover:text-accent"
                    @click="sortBy(column.sortKey)"
                  >
                    {{ column.header }}
                    <FontAwesomeIcon
                      :icon="getSortIcon(column.sortKey)"
                      class="text-ink-muted"
                    />
                  </button>
                  <template v-else>{{ column.header }}</template>
                </th>
                <th v-if="$slots.actions" scope="col" class="px-4 py-3 sm:px-6">
                  <span class="sr-only">Aktionen</span>
                </th>
              </tr>
            </thead>
            <tbody :id="tbodyId" class="divide-y divide-line">
              <tr
                v-for="row in rows"
                :key="idFunction(row)"
                :class="rowClass(row)"
              >
                <td
                  v-for="column in columns"
                  :key="column.name"
                  class="whitespace-nowrap px-4 py-3 sm:px-6"
                >
                  <slot :name="`cell-${column.name}`" :row="row">
                    {{ column.value?.(row) }}
                  </slot>
                </td>
                <td
                  v-if="$slots.actions"
                  class="whitespace-nowrap px-4 py-3 text-right sm:px-6"
                >
                  <div class="flex justify-end gap-2">
                    <slot name="actions" :row="row" />
                  </div>
                </td>
              </tr>
              <tr v-if="!rows.length">
                <td
                  :colspan="columns.length + ($slots.actions ? 1 : 0)"
                  class="px-4 py-6 text-center text-ink-muted sm:px-6"
                >
                  Keine Einträge gefunden.
                </td>
              </tr>
            </tbody>
          </table>

          <template #loading>
            <div
              class="flex items-center justify-center gap-2 py-4 text-sm text-ink-muted"
            >
              <div class="h-4 w-4 animate-pulse rounded-full bg-line"></div>
              Lade weitere Einträge …
            </div>
          </template>
        </InfiniteScroll>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, useId, watch, type PropType } from "vue";
import { InfiniteScroll, router, usePage } from "@inertiajs/vue3";

type Row = Record<string, any> & { _version?: number };

/**
 * Table that loads its rows page by page from the server, with server side search, sorting and
 * live update. See documentation/TABLE_POLLING.md.
 *
 * Cells show the value of the column as text; the slot "cell-<column name>" replaces a cell, the
 * slot "actions" adds buttons per row. Nothing is rendered as html.
 */
const props = defineProps({
  // name of the infinite scroll prop of the page (see app/Support/Table)
  data: {
    type: String,
    required: true,
  },
  columns: {
    type: Array as PropType<TableColumn[]>,
    required: true,
  },
  idFunction: {
    type: Function,
    default: (element: any) => element.id,
  },
  rowClass: {
    type: Function,
    default: () => ({}),
  },
  // show a search field (searched on the server)
  searchable: {
    type: Boolean,
    default: false,
  },
  // update the loaded rows periodically with the changes on the server
  live: {
    type: Boolean,
    default: true,
  },
  // interval of the live update
  liveInterval: {
    type: Number,
    default: 2500,
  },
});

const page = usePage();
const tbodyId = `table-${useId()}`;

const getId = (row: Row): number => props.idFunction(row);

// rows of the infinite scroll prop (all loaded pages)
const loadedRows = computed<Row[]>(
  () => (page.props[props.data] as { data?: Row[] } | undefined)?.data ?? [],
);

// local changes of the loaded rows (live update and own actions)
const patchedRows = ref(new Map<number, Row>());
const removedIds = ref(new Set<number>());
const newIds = ref(new Set<number>());

const isNewerOrEqual = (row: Row, otherRow: Row) =>
  (row._version ?? 0) >= (otherRow._version ?? 0);

const rows = computed<Row[]>(() => {
  // rows can be loaded twice (e.g. if rows were added while scrolling), the position of the
  // first occurrence and the newest version of the row is used
  const rowsById = new Map<number, Row>();
  for (const row of loadedRows.value) {
    const id = getId(row);
    const existingRow = rowsById.get(id);
    if (!existingRow || isNewerOrEqual(row, existingRow)) {
      rowsById.set(id, row);
    }
  }

  const result: Row[] = [];
  for (const [id, row] of rowsById) {
    if (removedIds.value.has(id)) {
      continue;
    }

    const patchedRow = patchedRows.value.get(id);
    result.push(
      patchedRow && isNewerOrEqual(patchedRow, row) ? patchedRow : row,
    );
  }

  return result;
});

const clearLocalChanges = () => {
  patchedRows.value = new Map();
  removedIds.value = new Set();
  newIds.value = new Set();
};

/**
 * Get the current query parameters of the page without the page parameter of the infinite scroll.
 */
const getQuery = (): Record<string, string> => {
  const query = Object.fromEntries(
    new URLSearchParams(window.location.search).entries(),
  );
  delete query.page;

  return query;
};

/**
 * Reload the table from the first page with changed query parameters (sorting, search).
 */
const reload = (changes: Record<string, string | null> = {}) => {
  const query = getQuery();
  for (const [key, value] of Object.entries(changes)) {
    if (value === null || value === "") {
      delete query[key];
    } else {
      query[key] = value;
    }
  }

  router.get(window.location.pathname, query, {
    only: [props.data],
    reset: [props.data],
    preserveState: true,
    preserveScroll: true,
    replace: true,
    onSuccess: clearLocalChanges,
  });
};

// sorting (on the server): ascending, descending, unsorted
const initialQuery = getQuery();
const sortKey = ref<string | null>(initialQuery.sort ?? null);
const sortDirection = ref<"asc" | "desc">(
  initialQuery.direction === "desc" ? "desc" : "asc",
);

const sortBy = (key: string) => {
  if (sortKey.value !== key) {
    sortKey.value = key;
    sortDirection.value = "asc";
  } else if (sortDirection.value === "asc") {
    sortDirection.value = "desc";
  } else {
    sortKey.value = null;
    sortDirection.value = "asc";
  }

  reload({
    sort: sortKey.value,
    direction: sortKey.value ? sortDirection.value : null,
  });
};

const ariaSort = (column: TableColumn) => {
  if (!column.sortKey || sortKey.value !== column.sortKey) {
    return undefined;
  }

  return sortDirection.value === "asc" ? "ascending" : "descending";
};

const getSortIcon = (key: string): Array<string> => {
  if (sortKey.value === key) {
    return sortDirection.value === "asc"
      ? ["fas", "arrow-down-short-wide"]
      : ["fas", "arrow-up-wide-short"];
  }

  return ["fas", "sort"];
};

// search (on the server)
const searchForm = ref({
  search: initialQuery.search ?? "",
});

let searchTimeout: ReturnType<typeof setTimeout> | null = null;
watch(
  () => searchForm.value.search,
  (search) => {
    // no reload if the search is already applied (e.g. synced from the url)
    if ((search ?? "") === (getQuery().search ?? "")) {
      if (searchTimeout) {
        clearTimeout(searchTimeout);
      }
      return;
    }

    if (searchTimeout) {
      clearTimeout(searchTimeout);
    }
    searchTimeout = setTimeout(() => reload({ search: search ?? null }), 300);
  },
);

// keep sorting and search in sync with the url (e.g. after a redirect to the page without parameters)
watch(
  () => page.url,
  () => {
    const query = getQuery();
    sortKey.value = query.sort ?? null;
    sortDirection.value = query.direction === "desc" ? "desc" : "asc";
    if ((query.search ?? "") !== (searchForm.value.search ?? "")) {
      searchForm.value.search = query.search ?? "";
    }
  },
);

// live update: only the changes of the loaded rows are fetched
let changesSince = Number(
  page.props.serverTime ?? Math.floor(Date.now() / 1000),
);

const fetchChanges = async () => {
  const url = new URL(window.location.href);
  url.searchParams.delete("page");
  url.searchParams.set("changes_since", String(changesSince));
  url.searchParams.set(
    "loaded_ids",
    loadedRows.value.map((row) => getId(row)).join(","),
  );

  const response = await fetch(url, {
    method: "GET",
    credentials: "include",
    headers: {
      Accept: "application/json",
    },
  });

  if (!response.ok) {
    return;
  }

  const changes: {
    time: number;
    rows: Row[];
    removed_ids: number[];
    new_ids: number[];
  } = await response.json();

  changesSince = changes.time;

  if (changes.rows.length) {
    const nextPatchedRows = new Map(patchedRows.value);
    changes.rows.forEach((row) => nextPatchedRows.set(getId(row), row));
    patchedRows.value = nextPatchedRows;
  }

  if (changes.removed_ids.length) {
    removedIds.value = new Set([...removedIds.value, ...changes.removed_ids]);
  }

  const loadedIds = new Set(loadedRows.value.map((row) => getId(row)));
  const unknownNewIds = changes.new_ids.filter((id) => !loadedIds.has(id));
  if (unknownNewIds.length) {
    newIds.value = new Set([...newIds.value, ...unknownNewIds]);
  }
};

const polling = props.live
  ? usePolling(fetchChanges, props.liveInterval)
  : null;

/**
 * Update fields of a loaded row immediately (e.g. after an own action).
 */
const patchRow = (id: number, fields: Row) => {
  const currentRow = rows.value.find((row) => getId(row) === id);
  if (!currentRow) {
    return;
  }

  const nextPatchedRows = new Map(patchedRows.value);
  nextPatchedRows.set(id, { ...currentRow, ...fields });
  patchedRows.value = nextPatchedRows;
};

/**
 * Remove a loaded row immediately (e.g. after an own action).
 */
const removeRow = (id: number) => {
  removedIds.value = new Set([...removedIds.value, id]);
};

/**
 * Fetch the changes of the loaded rows immediately.
 */
const refresh = () => polling?.run();

defineExpose({
  rows,
  patchRow,
  removeRow,
  refresh,
  reload,
});
</script>
