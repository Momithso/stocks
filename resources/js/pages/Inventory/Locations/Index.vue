<template>
  <UiPageHeader
    title="Standorte"
    description="Adresse, Raum, Lagerplatz und Fach. Die Kennziffer setzt sich aus den Kürzeln zusammen."
  >
    <UiButton
      v-if="can('locations.manage')"
      icon="plus"
      @click="openCreate(null)"
    >
      Adresse anlegen
    </UiButton>
  </UiPageHeader>

  <UiCard flush>
    <div
      v-if="locations.length"
      class="flex flex-wrap items-center gap-4 border-b border-line p-4 sm:px-6"
    >
      <UiCheckbox v-model="onlyUnlabeled" label="Noch ohne Etikett" />
      <UiButton
        v-if="can('labels.print') && onlyUnlabeled && unlabeled.length"
        variant="secondary"
        size="sm"
        icon="tag"
        class="ml-auto"
        :href="`/labels/print?locations=${unlabeled.map((location) => location.id).join(',')}`"
      >
        {{ unlabeled.length }} Etiketten drucken
      </UiButton>
    </div>
    <UiEmptyState
      v-if="!locations.length"
      icon="location-dot"
      title="Noch keine Standorte"
      description="Lege zuerst eine Adresse an, darin Räume, Lagerplätze und Fächer."
    />
    <ul v-else class="divide-y divide-line">
      <li
        v-for="location in visible"
        :key="location.id"
        class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2 sm:px-6"
      >
        <div
          class="flex min-w-0 flex-1 items-center gap-2"
          :style="{ paddingLeft: `${depth(location) * 1.25}rem` }"
        >
          <button
            v-if="hasChildren(location)"
            type="button"
            class="w-4 text-ink-muted hover:text-ink"
            :aria-label="
              collapsed.has(location.id) ? 'Aufklappen' : 'Zuklappen'
            "
            @click="toggle(location.id)"
          >
            <FontAwesomeIcon
              :icon="[
                'fas',
                collapsed.has(location.id) ? 'chevron-right' : 'chevron-down',
              ]"
            />
          </button>
          <span v-else class="w-4" />
          <UiLink
            :href="`/inventory/locations/${location.id}`"
            class="font-mono font-semibold"
          >
            {{ location.full_code }}
          </UiLink>
          <span class="truncate">{{ location.name }}</span>
          <span class="text-xs text-ink-muted">{{ location.type_label }}</span>
          <UiBadge v-if="location.items_count">{{
            location.items_count
          }}</UiBadge>
        </div>
        <div v-if="can('locations.manage')" class="flex gap-2">
          <UiButton
            v-if="location.type !== 'compartment'"
            variant="ghost"
            size="sm"
            icon="plus"
            @click="openCreate(location)"
          >
            {{ childLabel[location.type] }}
          </UiButton>
          <UiButton
            variant="ghost"
            size="sm"
            icon="pen"
            @click="openEdit(location)"
          >
            <span class="sr-only">Bearbeiten</span>
          </UiButton>
        </div>
      </li>
    </ul>
  </UiCard>

  <InventoryLocationModal
    v-if="editing"
    :location="editing.location"
    :parent-id="editing.parentId"
    :locations="locations"
    @close="editing = null"
  />
</template>

<script setup lang="ts">
import { computed, ref } from "vue";

type LocationRow = {
  id: number;
  parent_id: number | null;
  type: "address" | "room" | "shelf" | "compartment";
  type_label: string;
  code: string;
  name: string | null;
  full_code: string;
  description: string | null;
  items_count: number;
  label_printed_at: string | null;
};

/**
 * Tree of all locations. The server sorts them by full code, so every location comes after its
 * parent and the tree is a list with indentation.
 */
const props = defineProps<{ locations: LocationRow[] }>();
const { can } = usePermissions();

const levels = ["address", "room", "shelf", "compartment"];
const depth = (location: LocationRow) => levels.indexOf(location.type);
const childLabel = {
  address: "Raum",
  room: "Lagerplatz",
  shelf: "Fach",
  compartment: "",
};

const parents = computed(
  () => new Set(props.locations.map((location) => location.parent_id)),
);
const hasChildren = (location: LocationRow) => parents.value.has(location.id);

// collapsed locations hide everything below them
const collapsed = ref(new Set<number>());
const toggle = (id: number) => {
  const next = new Set(collapsed.value);
  if (!next.delete(id)) {
    next.add(id);
  }
  collapsed.value = next;
};
const byId = computed(
  () => new Map(props.locations.map((location) => [location.id, location])),
);
const isHidden = (location: LocationRow): boolean => {
  const parent = location.parent_id
    ? byId.value.get(location.parent_id)
    : undefined;
  return !!parent && (collapsed.value.has(parent.id) || isHidden(parent));
};
// filter "noch ohne Etikett": addresses get no label, so they are left out
const onlyUnlabeled = ref(false);
const unlabeled = computed(() =>
  props.locations.filter(
    (location) => !location.label_printed_at && location.type !== "address",
  ),
);
const visible = computed(() =>
  onlyUnlabeled.value
    ? unlabeled.value
    : props.locations.filter((location) => !isHidden(location)),
);

const editing = ref<{
  location: LocationRow | null;
  parentId: number | null;
} | null>(null);
const openCreate = (parent: LocationRow | null) =>
  (editing.value = { location: null, parentId: parent?.id ?? null });
const openEdit = (location: LocationRow) =>
  (editing.value = { location, parentId: location.parent_id });
</script>
