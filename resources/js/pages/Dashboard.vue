<template>
  <UiPageHeader
    :title="`Hallo ${page.props.auth!.user.firstname}!`"
    :description="page.props.organization.name"
  />

  <div
    v-if="components.length"
    class="grid gap-6 md:grid-cols-2 xl:grid-cols-3"
  >
    <component
      :is="component"
      v-for="(component, index) in components"
      :key="index"
    />
  </div>
  <UiCard v-else>
    <UiEmptyState
      icon="house"
      title="Noch nichts zu sehen"
      description="Hier erscheinen Kacheln der eingeschalteten Module, zum Beispiel überfällige Rückgaben."
    />
  </UiCard>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

/**
 * Start page. Tiles come from the modules (App\Support\Extensions\DashboardTiles).
 */
const props = defineProps<{ tiles: string[] }>();
const page = usePage<SharedProps>();

const components = computed(() =>
  props.tiles
    .map((name) => useModuleComponent(name))
    .filter((component) => component !== null),
);
</script>
