<template>
  <div class="flex flex-col items-center gap-2 px-4 py-10 text-center">
    <FontAwesomeIcon
      v-if="icon"
      :icon="['fas', icon]"
      class="h-8 w-8 text-ink-muted"
    />
    <p class="font-medium">{{ title }}</p>
    <p v-if="description" class="max-w-md text-sm text-ink-muted">
      {{ description }}
    </p>
    <div v-if="$slots.default" class="mt-2"><slot /></div>
  </div>
</template>

<script setup lang="ts">
/**
 * Shown instead of an empty list.
 */
defineProps({
  title: { type: String, required: true },
  description: { type: String, default: null },
  icon: { type: String, default: null },
});
</script>
