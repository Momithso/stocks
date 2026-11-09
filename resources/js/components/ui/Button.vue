<template>
  <InertiaLink
    v-if="href && !disabled"
    :href="href"
    :method="method"
    :as="method === 'get' ? 'a' : 'button'"
    :class="classes"
  >
    <FontAwesomeIcon v-if="icon" :icon="['fas', icon]" />
    <slot />
  </InertiaLink>
  <button v-else :type="type" :disabled="disabled" :class="classes">
    <FontAwesomeIcon v-if="icon" :icon="['fas', icon]" />
    <slot />
  </button>
</template>

<script setup lang="ts">
import { computed, type PropType } from "vue";
import { Link as InertiaLink } from "@inertiajs/vue3";

/**
 * Button, or a link that looks like a button when "href" is set.
 */
const props = defineProps({
  variant: {
    type: String as PropType<"primary" | "secondary" | "danger" | "ghost">,
    default: "primary",
  },
  // "lg" for touch screens (kiosk), at least 44 pixels high
  size: {
    type: String as PropType<"sm" | "md" | "lg">,
    default: "md",
  },
  type: {
    type: String as PropType<"button" | "submit">,
    default: "button",
  },
  href: { type: String, default: null },
  method: {
    type: String as PropType<"get" | "post" | "put" | "delete">,
    default: "get",
  },
  icon: { type: String, default: null },
  disabled: { type: Boolean, default: false },
});

const variants = {
  primary: "bg-accent text-white hover:bg-accent-hover",
  secondary:
    "bg-surface text-ink ring-1 ring-inset ring-line hover:bg-surface-muted",
  danger: "bg-red-600 text-white hover:bg-red-700 dark:bg-red-700",
  ghost: "text-ink-muted hover:bg-surface-muted hover:text-ink",
};

const sizes = {
  sm: "px-2.5 py-1.5 text-sm",
  md: "px-4 py-2 text-sm",
  lg: "min-h-[44px] px-6 py-3 text-lg",
};

const classes = computed(() => [
  "inline-flex select-none items-center justify-center gap-2 rounded-md font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-page disabled:cursor-not-allowed disabled:opacity-50",
  variants[props.variant],
  sizes[props.size],
]);
</script>
