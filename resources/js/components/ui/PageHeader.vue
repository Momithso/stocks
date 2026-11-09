<template>
  <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <Head :title="title" />
    <div>
      <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">
        {{ title }}
      </h1>
      <p v-if="description" class="mt-1 text-ink-muted">{{ description }}</p>
    </div>
    <div v-if="$slots.default" class="flex flex-wrap gap-2">
      <slot />
    </div>
  </div>
</template>

<script setup lang="ts">
import { Head } from "@inertiajs/vue3";

/**
 * Title of a page with optional actions on the right. Also sets the title of the browser tab.
 */
defineProps({
  title: { type: String, required: true },
  description: { type: String, default: null },
});
</script>
