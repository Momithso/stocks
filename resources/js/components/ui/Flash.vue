<template>
  <div v-if="messages.length" class="mb-6 space-y-3">
    <UiAlert
      v-for="message in messages"
      :key="message.type"
      :type="message.type"
      :message="message.text"
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

/**
 * Flash messages of the server (session "success", "error", "warning", "info").
 */
const page = usePage<SharedProps>();

const messages = computed(() =>
  (["error", "warning", "success", "info"] as const)
    .filter((type) => page.props.flash?.[type])
    .map((type) => ({ type, text: page.props.flash[type] as string })),
);
</script>
