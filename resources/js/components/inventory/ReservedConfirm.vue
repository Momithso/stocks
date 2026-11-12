<template>
  <UiAlert v-if="message" type="warning">
    {{ message }}
    <button
      type="button"
      class="ml-1 font-bold underline"
      :disabled="processing"
      @click="$emit('confirm')"
    >
      Ja, trotzdem
    </button>
  </UiAlert>
</template>

<script setup lang="ts">
/**
 * Question of the server before an action that takes an item from a booking (error "confirm",
 * see App\Support\Extensions\ItemExtensions::ensureConfirmed()): "confirm" sends the form again
 * with "confirmed".
 */
defineProps<{ message?: string | null; processing?: boolean }>();
defineEmits<{ confirm: [] }>();
</script>
