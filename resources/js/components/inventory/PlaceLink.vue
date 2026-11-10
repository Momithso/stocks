<template>
  <UiLink v-if="place" :href="href">
    <span class="font-mono font-semibold">{{ place.code }}</span>
    <span v-if="place.name" class="ml-1">{{ place.name }}</span>
  </UiLink>
  <span v-else class="text-ink-muted">{{ empty }}</span>
</template>

<script setup lang="ts">
import { computed, type PropType } from "vue";

/**
 * Link to a place: the page of the location or of the box.
 */
const props = defineProps({
  place: { type: Object as PropType<Place | null>, default: null },
  empty: { type: String, default: "—" },
});

const href = computed(() =>
  props.place?.type === "location"
    ? `/inventory/locations/${props.place.id}`
    : `/inventory/items/${props.place?.id}`,
);
</script>
