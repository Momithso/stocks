<template>
  <UiModal :title="title" @close="emit('close')">
    <div class="space-y-6">
      <UiAlert type="warning" :message="message" />
      <div class="flex flex-col gap-3 sm:flex-row-reverse">
        <UiButton
          :variant="variant"
          :disabled="processing"
          class="flex-1"
          @click="emit('confirm')"
        >
          {{ confirmLabel }}
        </UiButton>
        <UiButton variant="secondary" class="flex-1" @click="emit('close')">
          Abbrechen
        </UiButton>
      </div>
    </div>
  </UiModal>
</template>

<script setup lang="ts">
import type { PropType } from "vue";

/**
 * Asks before a destructive action.
 */
defineProps({
  title: { type: String, required: true },
  message: { type: String, required: true },
  confirmLabel: { type: String, default: "Löschen" },
  variant: {
    type: String as PropType<"primary" | "danger">,
    default: "danger",
  },
  processing: { type: Boolean, default: false },
});

const emit = defineEmits<{
  close: [];
  confirm: [];
}>();
</script>
