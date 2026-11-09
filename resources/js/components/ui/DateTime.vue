<template>
  <time v-if="value" :datetime="value">{{ formatted }}</time>
  <span v-else class="text-ink-muted">—</span>
</template>

<script setup lang="ts">
import { computed } from "vue";

/**
 * Date (and time) in German format, e.g. "09.11.2026, 14:30".
 */
const props = defineProps({
  value: { type: String, default: null },
  withTime: { type: Boolean, default: true },
});

const formatted = computed(() =>
  new Intl.DateTimeFormat("de-DE", {
    dateStyle: "medium",
    timeStyle: props.withTime ? "short" : undefined,
  }).format(new Date(props.value)),
);
</script>
