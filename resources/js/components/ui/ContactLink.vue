<template>
  <a
    v-if="value"
    :href="href"
    class="flex min-h-[28px] w-fit items-center gap-1.5 break-all text-accent hover:underline"
  >
    <FontAwesomeIcon
      v-if="icon"
      :icon="['fas', kind === 'phone' ? 'phone' : 'envelope']"
      class="h-3.5 w-3.5 shrink-0"
    />{{ value }}
  </a>
</template>

<script setup lang="ts">
import { computed, type PropType } from "vue";

/**
 * Phone number or e-mail address as link on a line of its own: a tap opens the phone or the mail app
 * (tel:, mailto:).
 */
const props = defineProps({
  kind: { type: String as PropType<"phone" | "email">, required: true },
  value: { type: String, default: null },
  icon: { type: Boolean, default: true },
});

// a phone link only keeps digits and a leading plus
const href = computed(() =>
  props.kind === "phone"
    ? `tel:${(props.value ?? "").replace(/(?!^\+)[^\d]/g, "")}`
    : `mailto:${props.value}`,
);
</script>
