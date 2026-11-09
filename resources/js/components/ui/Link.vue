<template>
  <a
    v-if="isExternal"
    :href="href"
    target="_blank"
    rel="noopener"
    :class="classes"
  >
    <slot />
  </a>
  <InertiaLink v-else :href="href" :class="classes">
    <slot />
  </InertiaLink>
</template>

<script setup lang="ts">
import { computed, type PropType } from "vue";
import { Link as InertiaLink } from "@inertiajs/vue3";

/**
 * Text link. External links open in a new tab.
 */
const props = defineProps({
  href: { type: String, required: true },
  variant: {
    type: String as PropType<"accent" | "muted">,
    default: "accent",
  },
});

const isExternal = computed(() => /^https?:\/\//.test(props.href));

const classes = computed(() => [
  "font-medium hover:underline focus:outline-none focus-visible:underline",
  props.variant === "accent" ? "text-accent" : "text-ink-muted",
]);
</script>
