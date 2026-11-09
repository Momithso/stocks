<template>
  <span
    :class="[
      'inline-flex shrink-0 items-center justify-center rounded-full bg-accent/15 font-semibold text-accent',
      size === 'lg' ? 'h-16 w-16 text-xl' : 'h-8 w-8 text-xs',
    ]"
    aria-hidden="true"
  >
    {{ initials }}
  </span>
</template>

<script setup lang="ts">
import { computed, type PropType } from "vue";

/**
 * Circle with the initials of a person.
 */
const props = defineProps({
  firstname: { type: String, required: true },
  lastname: { type: String, default: "" },
  size: { type: String as PropType<"md" | "lg">, default: "md" },
});

const initials = computed(() =>
  `${props.firstname.charAt(0)}${props.lastname.charAt(0)}`.toUpperCase(),
);
</script>
