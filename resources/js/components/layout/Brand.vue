<template>
  <!-- in the sidebar the gap to the name equals the space left of the logo (px-4) -->
  <span
    :class="['flex min-w-0 items-center', size === 'nav' ? 'gap-4' : 'gap-2']"
  >
    <img
      v-if="organization.logo_url"
      :src="organization.logo_url"
      alt=""
      :class="['shrink-0 object-contain', sizes[size]]"
    />
    <span v-if="!logoOnly" class="truncate">
      {{ text ?? organization.short_name }}
    </span>
  </span>
</template>

<script setup lang="ts">
import { computed, type PropType } from "vue";
import { usePage } from "@inertiajs/vue3";

/**
 * Logo (if one was uploaded in the settings) and name of the organization, e.g. top left in the
 * sidebar, on the login page and in the kiosk.
 */
defineProps({
  // text next to the logo, the short name by default
  text: { type: String, default: null },
  // "nav": a square of 64 pixels, top left in the sidebar next to the name
  size: {
    type: String as PropType<"sm" | "nav">,
    default: "sm",
  },
  // only the logo, e.g. in the collapsed sidebar
  logoOnly: { type: Boolean, default: false },
});

const sizes = {
  sm: "h-8 max-w-[8rem]",
  nav: "h-16 w-16",
};

const organization = computed(() => usePage<SharedProps>().props.organization);
</script>
