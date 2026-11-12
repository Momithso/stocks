<template>
  <UiPageHeader title="Beispiel" description="Seite des Beispielmoduls.">
    <UiButton v-if="kiosk" size="lg" variant="secondary" :href="url('/home')">
      Zurück
    </UiButton>
  </UiPageHeader>
  <UiCard>
    <p>
      Diese Seite kommt aus <code>modules/Example</code>. Die Tabelle
      <code>example_notes</code> hat {{ notes }} Einträge.
    </p>
  </UiCard>
</template>

<script setup lang="ts">
/**
 * Pages of modules use the components of the core (Ui…) like pages of the core. The same page
 * runs on the kiosk: links go through url(), which puts the prefix of the kiosk in front there, and the layout
 * comes from the surface.
 */
defineProps<{ notes: number }>();

const { kiosk, url } = useSurface();
</script>
