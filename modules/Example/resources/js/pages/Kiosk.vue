<template>
  <UiPageHeader
    title="Beispiel am Kiosk"
    :description="`Entsperrt von ${person.firstname} ${person.lastname}`"
  >
    <UiButton size="lg" variant="secondary" href="/kiosk/home">Zurück</UiButton>
  </UiPageHeader>
</template>

<script setup lang="ts">
/**
 * Kiosk page of the module, gets the kiosk layout by its name "Example::Kiosk".
 */
defineProps<{ person: { firstname: string; lastname: string } }>();
</script>
