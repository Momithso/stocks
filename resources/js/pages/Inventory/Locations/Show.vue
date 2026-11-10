<template>
  <UiPageHeader
    :title="location.full_code"
    :description="
      [location.type_label, location.name].filter(Boolean).join(' · ')
    "
  >
    <UiButton
      v-if="can('inventory.edit') && location.type !== 'address'"
      icon="plus"
      :href="`/inventory/items/create?place=${encodeURIComponent(location.full_code)}`"
    >
      Gegenstand hier anlegen
    </UiButton>
    <UiButton
      v-if="can('labels.print')"
      variant="secondary"
      icon="tag"
      :href="`/labels/print?locations=${location.id}`"
    >
      Etikett drucken
    </UiButton>
    <UiButton
      v-if="can('labels.print') && children.length"
      variant="secondary"
      icon="layer-group"
      :href="`/labels/print?below=${location.id}`"
    >
      Etiketten für alle Lagerplätze und Fächer darunter
    </UiButton>
    <UiButton
      v-if="can('locations.manage')"
      variant="secondary"
      icon="pen"
      @click="editOpen = true"
    >
      Bearbeiten
    </UiButton>
  </UiPageHeader>

  <nav
    v-if="path.length"
    class="-mt-4 mb-6 flex flex-wrap gap-2 text-sm"
    aria-label="Pfad"
  >
    <UiLink href="/inventory/locations" variant="muted">Standorte</UiLink>
    <template v-for="parent in path" :key="parent.id">
      <span class="text-ink-muted">›</span>
      <UiLink :href="`/inventory/locations/${parent.id}`" variant="muted">
        {{ parent.full_code }}
      </UiLink>
    </template>
  </nav>

  <p v-if="location.description" class="mb-6 whitespace-pre-line">
    {{ location.description }}
  </p>

  <div class="grid gap-6 lg:grid-cols-2">
    <UiCard v-if="location.type !== 'compartment'" title="Darin" flush>
      <ul v-if="children.length" class="divide-y divide-line">
        <li
          v-for="child in children"
          :key="child.id"
          class="flex items-center gap-2 px-4 py-3 sm:px-6"
        >
          <UiLink
            :href="`/inventory/locations/${child.id}`"
            class="font-mono font-semibold"
          >
            {{ child.full_code }}
          </UiLink>
          <span class="truncate">{{ child.name }}</span>
          <UiBadge v-if="child.items_count" class="ml-auto">{{
            child.items_count
          }}</UiBadge>
        </li>
      </ul>
      <UiEmptyState v-else icon="location-dot" title="Keine Standorte darin" />
    </UiCard>

    <UiCard title="Gegenstände hier" flush>
      <ul v-if="items.length" class="divide-y divide-line">
        <li
          v-for="item in items"
          :key="item.id"
          class="flex flex-wrap items-center gap-2 px-4 py-3 sm:px-6"
        >
          <FontAwesomeIcon
            v-if="item.is_container"
            :icon="['fas', 'box']"
            class="text-ink-muted"
          />
          <UiLink :href="`/inventory/items/${item.id}`">{{ item.name }}</UiLink>
          <span class="font-mono text-xs text-ink-muted">{{
            item.number
          }}</span>
          <UiBadge v-if="item.kind === 'bulk'"
            >{{ item.quantity }} {{ item.unit }}</UiBadge
          >
          <span v-if="item.is_container" class="text-xs text-ink-muted">
            {{ item.contents_count }} darin
          </span>
        </li>
      </ul>
      <UiEmptyState v-else icon="boxes-stacked" title="Hier liegt nichts" />
    </UiCard>
  </div>

  <InventoryLocationModal
    v-if="editOpen"
    :location="location"
    :parent-id="location.parent_id"
    :locations="locations"
    @close="editOpen = false"
  />
</template>

<script setup lang="ts">
import { ref } from "vue";

type LocationSummary = {
  id: number;
  type: "address" | "room" | "shelf" | "compartment";
  type_label: string;
  code: string;
  name: string | null;
  full_code: string;
};

/**
 * A location with the locations and the items directly in it. Items in boxes are listed on the
 * page of the box.
 */
defineProps<{
  location: LocationSummary & {
    parent_id: number | null;
    description: string | null;
  };
  path: LocationSummary[];
  children: (LocationSummary & { items_count: number })[];
  items: {
    id: number;
    number: string;
    name: string;
    kind: string;
    quantity: number;
    unit: string | null;
    is_container: boolean;
    contents_count: number;
  }[];
  // all locations, for moving this one
  locations: LocationSummary[];
}>();

const { can } = usePermissions();
const editOpen = ref(false);
</script>
