<template>
  <button
    type="button"
    class="rounded-md p-2 text-ink-muted hover:bg-surface-muted hover:text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
    :aria-label="
      colorMode === 'dark'
        ? 'Hellen Modus einschalten'
        : 'Dunklen Modus einschalten'
    "
    @click="toggleColorMode()"
  >
    <FontAwesomeIcon :icon="['fas', colorMode === 'dark' ? 'sun' : 'moon']" />
  </button>
</template>

<script setup lang="ts">
const { colorMode, toggleColorMode } = useColorMode();
</script>
