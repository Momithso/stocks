<template>
  <div class="space-y-3">
    <form class="flex gap-2" @submit.prevent="submit">
      <input
        v-model="typed"
        type="text"
        autocomplete="off"
        autocapitalize="characters"
        :placeholder="placeholder"
        aria-label="Code"
        class="block w-full rounded-md border-0 bg-surface py-2.5 font-mono text-ink shadow-sm ring-1 ring-inset ring-line focus:ring-2 focus:ring-inset focus:ring-accent"
      />
      <UiButton type="submit" variant="secondary">OK</UiButton>
      <UiButton
        variant="secondary"
        :icon="camera ? 'xmark' : 'camera'"
        :aria-label="camera ? 'Kamera aus' : 'Kamera an'"
        @click="camera = !camera"
      />
    </form>
    <InventoryCameraScanner
      v-if="camera"
      class="aspect-video w-full"
      autostart
      @scan="emit('scan', $event)"
    />
  </div>
</template>

<script setup lang="ts">
import { ref } from "vue";

/**
 * Scanning on the pages of the loan module: typing, a hand scanner (also without a focused field)
 * or the camera. Every code is emitted as "scan".
 */
withDefaults(defineProps<{ placeholder?: string }>(), {
  placeholder: "Code scannen oder eintippen",
});
const emit = defineEmits<{ scan: [code: string] }>();

const typed = ref("");
const camera = ref(false);

useScannerInput((code) => emit("scan", code));

const submit = () => {
  if (typed.value.trim()) {
    emit("scan", typed.value.trim());
    typed.value = "";
  }
};
</script>
