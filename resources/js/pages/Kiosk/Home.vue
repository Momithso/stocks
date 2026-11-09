<template>
  <Head title="Kiosk" />
  <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
    <div class="flex items-center gap-4">
      <UiAvatar
        :firstname="person.firstname"
        :lastname="person.lastname"
        size="lg"
      />
      <h1 class="text-2xl font-bold">Hallo {{ person.firstname }}!</h1>
    </div>
    <UiButton
      size="lg"
      variant="secondary"
      icon="lock"
      href="/kiosk/logout"
      method="post"
    >
      Fertig und sperren
    </UiButton>
  </div>

  <UiEmptyState
    v-if="!tiles.length"
    icon="tablet-screen-button"
    title="Noch keine Kiosk-Funktionen"
    description="Warenausgang und Wareneingang kommen mit den Modulen Verleih und Kiosk."
  />
  <div v-else class="grid gap-4 sm:grid-cols-2">
    <InertiaLink
      v-for="tile in tiles"
      :key="tile.href"
      :href="tile.href"
      class="flex min-h-[160px] flex-col items-center justify-center gap-4 rounded-lg bg-surface p-6 text-xl font-semibold shadow ring-1 ring-line/50 hover:ring-accent"
    >
      <FontAwesomeIcon
        :icon="['fas', tile.icon]"
        class="h-10 w-10 text-accent"
      />
      {{ tile.title }}
    </InertiaLink>
  </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted } from "vue";
import { Head, Link as InertiaLink, router } from "@inertiajs/vue3";

/**
 * Home page of the unlocked kiosk with the tiles of the active modules.
 */
const props = defineProps<{
  person: { id: number; firstname: string; lastname: string };
  tiles: { title: string; href: string; icon: string }[];
  sessionTimeout: number;
}>();

// back to the lock screen when the session runs out on the server
let timeout: ReturnType<typeof setTimeout> | undefined;
const resetTimeout = () => {
  clearTimeout(timeout);
  timeout = setTimeout(
    () => router.post("/kiosk/logout"),
    props.sessionTimeout * 1000,
  );
};
onMounted(() => {
  resetTimeout();
  window.addEventListener("pointerdown", resetTimeout);
});
onBeforeUnmount(() => {
  window.removeEventListener("pointerdown", resetTimeout);
  clearTimeout(timeout);
});
</script>
