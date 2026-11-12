<template>
  <UiPageHeader
    :title="misplaced ? 'Ohne Platz' : 'Gegenstände'"
    :description="
      misplaced
        ? 'Gegenstände ohne aktuellen Ort und solche, die nicht an ihrem Stammplatz liegen.'
        : undefined
    "
  >
    <UiButton
      v-if="can('labels.print') && selected.length"
      variant="secondary"
      icon="tag"
      :href="`/labels/print?items=${selected.join(',')}`"
    >
      {{ selected.length }} Etiketten drucken
    </UiButton>
    <UiButton
      v-if="can('inventory.edit')"
      href="/inventory/items/create"
      icon="plus"
    >
      Anlegen
    </UiButton>
  </UiPageHeader>

  <UiAlert v-if="found" type="info" class="mb-4">
    {{ found.type === "item" ? "Gegenstand" : "Standort" }}
    {{ found.code }} gefunden:
    <UiLink :href="found.href">{{ found.name ?? found.code }} öffnen</UiLink>
  </UiAlert>

  <UiCard flush>
    <div
      v-if="!misplaced"
      class="grid gap-4 border-b border-line p-4 sm:grid-cols-2 sm:px-6 lg:grid-cols-4"
    >
      <UiSelect
        v-model="filter.category"
        label="Kategorie"
        :options="categories"
        @update:model-value="applyFilters"
      />
      <UiSelect
        v-model="filter.condition"
        label="Zustand"
        :options="conditions"
        @update:model-value="applyFilters"
      />
      <UiCheckbox
        v-model="filter.retired"
        label="Ausgemusterte zeigen"
        class="lg:mt-7"
        @update:model-value="applyFilters"
      />
      <UiCheckbox
        v-model="filter.unlabeled"
        label="Noch ohne Etikett"
        class="lg:mt-7"
        @update:model-value="applyFilters"
      />
    </div>

    <UiTable
      ref="table"
      data="items"
      :columns="columns"
      searchable
      search-placeholder="Name, Artikelnummer, Kategorie oder Kennziffer"
      stacked
      @search="lookup"
    >
      <template #cell-name="{ row }">
        <div class="flex items-center gap-3">
          <!-- selection for printing several labels at once -->
          <input
            v-if="can('labels.print') && !row.retired"
            v-model="selected"
            type="checkbox"
            :value="row.id"
            class="h-4 w-4 shrink-0 rounded border-line text-accent focus:ring-accent"
            :aria-label="`${row.name} auswählen`"
          />
          <img
            v-if="row.photo_url"
            :src="row.photo_url"
            alt=""
            class="h-10 w-10 shrink-0 rounded object-cover"
            loading="lazy"
          />
          <div
            v-else
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-surface-muted text-ink-muted"
          >
            <FontAwesomeIcon
              :icon="['fas', row.is_container ? 'box' : 'boxes-stacked']"
            />
          </div>
          <div class="min-w-0">
            <UiLink :href="`/inventory/items/${row.id}`" class="font-medium">
              {{ row.name }}
            </UiLink>
            <p class="font-mono text-xs text-ink-muted">{{ row.number }}</p>
          </div>
        </div>
      </template>
      <template #cell-quantity="{ row }">
        <span v-if="row.kind === 'bulk'"
          >{{ row.quantity }} {{ row.unit }}</span
        >
      </template>
      <template #cell-place="{ row }">
        <InventoryPlaceLink :place="row.place" empty="kein Ort" />
        <UiBadge v-if="row.away_from_home" variant="warning" class="ml-2">
          nicht am Stammplatz
        </UiBadge>
        <UiBadge
          v-for="badge in row.badges"
          :key="badge.text"
          :variant="badge.variant === 'info' ? 'accent' : badge.variant"
          class="ml-2"
        >
          {{ badge.text }}
        </UiBadge>
      </template>
      <template #cell-condition="{ row }">
        <UiBadge :variant="row.condition_is_defect ? 'danger' : 'neutral'">
          {{ row.condition }}
        </UiBadge>
        <UiBadge v-if="row.retired" class="ml-2">ausgemustert</UiBadge>
      </template>
    </UiTable>
  </UiCard>
</template>

<script setup lang="ts">
import { ref } from "vue";

type Found = {
  type: "item" | "location";
  name: string | null;
  number?: string;
  full_code?: string;
  code: string;
  href: string;
};

/**
 * List of all items, or of the items without place ("Ohne Platz").
 */
const props = defineProps<{
  misplaced: boolean;
  categories: Option[];
  conditions: Option[];
  filters: {
    category?: string;
    condition?: string;
    retired?: string;
    unlabeled?: string;
  };
}>();

const { can } = usePermissions();
const table = ref<{
  reload: (changes: Record<string, string | null>) => void;
} | null>(null);

const columns: TableColumn[] = [
  { name: "name", header: "Gegenstand", sortKey: "name" },
  { name: "quantity", header: "Menge" },
  { name: "category", header: "Kategorie", value: (row) => row.category },
  { name: "place", header: "Ort" },
  { name: "condition", header: "Zustand", sortKey: "condition" },
];

const filter = ref({
  category: props.filters.category ? Number(props.filters.category) : null,
  condition: props.filters.condition ? Number(props.filters.condition) : null,
  retired: props.filters.retired === "1",
  unlabeled: props.filters.unlabeled === "1",
});

// items chosen for printing labels
const selected = ref<number[]>([]);

const applyFilters = () =>
  table.value?.reload({
    category: filter.value.category ? String(filter.value.category) : null,
    condition: filter.value.condition ? String(filter.value.condition) : null,
    retired: filter.value.retired ? "1" : null,
    unlabeled: filter.value.unlabeled ? "1" : null,
  });

// a search that is exactly an item number or a location code offers to open it
const found = ref<Found | null>(null);
const lookup = async (search: string) => {
  found.value = null;
  if (!search.trim()) {
    return;
  }

  const response = await fetch(
    `/inventory/lookup?code=${encodeURIComponent(search)}`,
    {
      headers: { Accept: "application/json" },
    },
  );
  if (response.ok) {
    const data = await response.json();
    found.value = { ...data, code: data.number ?? data.full_code };
  }
};
</script>
