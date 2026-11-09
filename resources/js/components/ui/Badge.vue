<template>
  <span
    :class="[
      'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
      variants[variant],
    ]"
  >
    <slot />
  </span>
</template>

<script setup lang="ts">
import type { PropType } from "vue";

/**
 * Small label, e.g. for a role or a status. Always text, colour only adds to it.
 */
defineProps({
  variant: {
    type: String as PropType<
      "neutral" | "accent" | "success" | "warning" | "danger"
    >,
    default: "neutral",
  },
});

const variants = {
  neutral: "bg-surface-muted text-ink ring-1 ring-inset ring-line",
  accent: "bg-accent/10 text-accent ring-1 ring-inset ring-accent/30",
  success:
    "bg-green-50 text-green-700 ring-1 ring-inset ring-green-600/20 dark:bg-green-950 dark:text-green-300",
  warning:
    "bg-yellow-50 text-yellow-800 ring-1 ring-inset ring-yellow-600/20 dark:bg-yellow-950 dark:text-yellow-300",
  danger:
    "bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20 dark:bg-red-950 dark:text-red-300",
};
</script>
