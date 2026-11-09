<template>
  <UiPageHeader title="Beispiel" description="Seite des Beispielmoduls." />
  <UiCard>
    <p>
      Diese Seite kommt aus <code>modules/Example</code>. Die Tabelle
      <code>example_notes</code> hat {{ notes }} Einträge.
    </p>
  </UiCard>
</template>

<script setup lang="ts">
/**
 * Pages of modules use the components of the core (Ui…) like pages of the core.
 */
defineProps<{ notes: number }>();
</script>
