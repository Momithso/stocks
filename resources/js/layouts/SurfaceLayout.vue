<template>
  <component :is="layout">
    <slot />
  </component>
</template>

<script setup lang="ts">
import { computed, type Component } from "vue";
import { usePage } from "@inertiajs/vue3";
import AppLayout from "./AppLayout.vue";

/**
 * Default layout of all pages: the layout the surface names (e.g. "Kiosk::KioskLayout" on the
 * kiosk), otherwise the layout of the pc and the phone. So the same page runs in two layouts.
 * Layouts of modules lie in modules/<Module>/resources/js/layouts.
 */
const moduleLayouts = import.meta.glob<{ default: Component }>(
  "../../../modules/*/resources/js/layouts/*.vue",
  { eager: true },
);

const page = usePage<SharedProps>();

const layout = computed(() => {
  const name = page.props.surface?.layout;
  if (!name) {
    return AppLayout;
  }
  const [module, file] = name.split("::");
  return (
    moduleLayouts[`../../../modules/${module}/resources/js/layouts/${file}.vue`]
      ?.default ?? AppLayout
  );
});
</script>
