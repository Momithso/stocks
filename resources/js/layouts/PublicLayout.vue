<template>
  <div class="min-h-screen">
    <header
      class="flex h-14 items-center justify-between border-b border-line bg-surface px-4"
    >
      <span class="font-bold tracking-tight"><LayoutBrand /></span>
      <UiColorModeButton />
    </header>
    <main class="mx-auto max-w-2xl px-4 py-6">
      <slot />
    </main>
    <footer class="flex justify-center gap-4 pb-8 text-sm">
      <UiLink
        v-if="organization.imprint_url"
        :href="organization.imprint_url"
        variant="muted"
      >
        Impressum
      </UiLink>
      <UiLink
        v-if="organization.privacy_url"
        :href="organization.privacy_url"
        variant="muted"
      >
        Datenschutz
      </UiLink>
    </footer>
  </div>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

/**
 * Layout of the public view of scanned labels: brand on top, no menu.
 */
const page = usePage<SharedProps>();
const organization = computed(() => page.props.organization);
</script>
