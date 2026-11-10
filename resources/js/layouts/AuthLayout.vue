<template>
  <div
    class="relative flex min-h-screen flex-col items-center justify-center px-4 py-10"
  >
    <UiColorModeButton class="absolute right-4 top-4" />
    <!-- logo and name centered above the form -->
    <!-- large logo: a square of 250 pixels on the pc, smaller on narrow screens -->
    <img
      v-if="organization.logo_url"
      :src="organization.logo_url"
      alt=""
      class="mb-8 size-[min(250px,60vw)] object-contain"
    />
    <div class="w-full max-w-md">
      <p class="mb-6 text-center text-2xl font-bold tracking-tight">
        {{ organization.name }}
      </p>
      <div
        class="rounded-lg bg-surface px-4 py-8 shadow ring-1 ring-line/50 sm:px-8"
      >
        <UiFlash />
        <slot />
      </div>
      <div class="mt-6 flex justify-center gap-4 text-sm">
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
        <UiLink :href="repositoryUrl" variant="muted">Stocks</UiLink>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

/**
 * Layout of login, invitation, password reset and setup: one centered card.
 */
const page = usePage<SharedProps>();
const organization = computed(() => page.props.organization);
const repositoryUrl = __PACKAGE_REPOSITORY_URL__;
</script>
