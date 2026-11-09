<template>
  <section class="rounded-lg bg-surface shadow ring-1 ring-line/50">
    <header
      v-if="title || $slots.actions"
      class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3 sm:px-6"
    >
      <div>
        <h2 class="text-base font-semibold">{{ title }}</h2>
        <p v-if="description" class="text-sm text-ink-muted">
          {{ description }}
        </p>
      </div>
      <div v-if="$slots.actions" class="flex gap-2">
        <slot name="actions" />
      </div>
    </header>
    <div :class="flush ? '' : 'p-4 sm:p-6'">
      <slot />
    </div>
    <footer
      v-if="$slots.footer"
      class="flex justify-end gap-2 border-t border-line px-4 py-3 sm:px-6"
    >
      <slot name="footer" />
    </footer>
  </section>
</template>

<script setup lang="ts">
/**
 * White (dark: gray) box for content, optionally with a title and actions.
 */
defineProps({
  title: { type: String, default: null },
  description: { type: String, default: null },
  // no padding, e.g. for tables that reach the border
  flush: { type: Boolean, default: false },
});
</script>
