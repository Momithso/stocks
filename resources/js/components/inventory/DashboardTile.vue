<template>
  <UiCard title="Inventar">
    <dl v-if="stats" class="grid grid-cols-2 gap-4">
      <div>
        <dt class="text-sm text-ink-muted">Gegenstände</dt>
        <dd class="text-2xl font-semibold">{{ stats.items }}</dd>
      </div>
      <div>
        <dt class="text-sm text-ink-muted">Ohne Platz</dt>
        <dd class="text-2xl font-semibold">
          <UiLink href="/inventory/misplaced">{{ stats.misplaced }}</UiLink>
        </dd>
      </div>
    </dl>
    <div v-else class="h-16 animate-pulse rounded bg-surface-muted" />
  </UiCard>
</template>

<script setup lang="ts">
import { onMounted, ref } from "vue";

/**
 * Dashboard tile with the amount of items and how many are not at their place.
 */
const stats = ref<{ items: number; misplaced: number } | null>(null);

onMounted(async () => {
  const response = await fetch("/inventory/stats", {
    headers: { Accept: "application/json" },
  });
  if (response.ok) {
    stats.value = await response.json();
  }
});
</script>
