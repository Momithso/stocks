<template>
  <nav
    v-if="paginator.prev_page_url || paginator.next_page_url"
    class="flex items-center justify-between gap-4 px-4 py-3"
    aria-label="Seitennavigation"
  >
    <UiButton
      v-if="paginator.prev_page_url"
      :href="paginator.prev_page_url"
      variant="secondary"
      size="sm"
      icon="chevron-left"
    >
      Neuere
    </UiButton>
    <span v-else />
    <span class="text-sm text-ink-muted">
      {{ paginator.from }}–{{ paginator.to }} von {{ paginator.total }}
    </span>
    <UiButton
      v-if="paginator.next_page_url"
      :href="paginator.next_page_url"
      variant="secondary"
      size="sm"
    >
      Ältere
      <FontAwesomeIcon :icon="['fas', 'chevron-right']" />
    </UiButton>
    <span v-else />
  </nav>
</template>

<script setup lang="ts">
import type { PropType } from "vue";

/**
 * Previous and next page of a Laravel paginator.
 */
defineProps({
  paginator: {
    type: Object as PropType<Pagination.Paginator<unknown>>,
    required: true,
  },
});
</script>
