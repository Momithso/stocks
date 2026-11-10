<template>
  <div class="flex min-h-screen flex-col text-lg">
    <header
      class="flex h-16 items-center justify-between border-b border-line bg-surface px-6"
    >
      <p class="min-w-0 text-xl font-bold tracking-tight">
        <LayoutBrand :text="`${organization.short_name} · Kiosk`" />
      </p>
      <UiColorModeButton />
    </header>
    <main class="mx-auto w-full max-w-5xl flex-1 px-6 py-8">
      <UiFlash />
      <slot />
    </main>
  </div>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

/**
 * Layout of the kiosk on tablets: full screen, no menu, large text.
 */
const page = usePage<SharedProps>();
const organization = computed(() => page.props.organization);
</script>
