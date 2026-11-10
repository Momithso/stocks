<template>
  <div class="space-y-2">
    <p v-if="label" class="text-sm font-medium">{{ label }}</p>
    <div class="flex flex-wrap gap-2">
      <div v-for="(preview, index) in previews" :key="preview" class="relative">
        <img :src="preview" alt="" class="h-16 w-16 rounded object-cover" />
        <button
          type="button"
          class="absolute -right-2 -top-2 flex h-6 w-6 items-center justify-center rounded-full bg-surface text-xs text-ink-muted shadow ring-1 ring-line hover:text-red-600"
          :aria-label="`Foto ${index + 1} entfernen`"
          @click="remove(index)"
        >
          <FontAwesomeIcon :icon="['fas', 'xmark']" />
        </button>
      </div>
      <!-- every choice adds photos: phones take one photo per camera call -->
      <label
        v-if="model.length < max"
        :class="[
          'flex h-16 w-16 cursor-pointer flex-col items-center justify-center gap-1 rounded border-2 border-dashed border-line text-xs text-ink-muted hover:border-accent hover:text-accent',
          busy ? 'pointer-events-none opacity-50' : '',
        ]"
      >
        <FontAwesomeIcon :icon="['fas', busy ? 'rotate' : 'camera']" />
        {{ model.length ? "Weiteres" : "Foto" }}
        <input
          type="file"
          accept="image/jpeg,image/png,image/webp"
          multiple
          class="sr-only"
          @change="add"
        />
      </label>
    </div>
    <p v-if="hint" class="text-sm text-ink-muted">{{ hint }}</p>
    <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
  </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from "vue";

/**
 * Photos for a form. Each choice adds to the photos already chosen (the camera of a phone takes
 * one photo at a time); every photo can be removed again. Photos are scaled down in the browser.
 */
const props = withDefaults(
  defineProps<{
    label?: string | null;
    hint?: string | null;
    error?: string | null;
    max?: number;
  }>(),
  { label: null, hint: null, error: null, max: 10 },
);
const model = defineModel<File[]>({ required: true });

const { compressImages } = useImageCompression();
const busy = ref(false);

const add = async (event: Event) => {
  const input = event.target as HTMLInputElement;
  busy.value = true;
  const added = await compressImages(input.files);
  busy.value = false;
  model.value = [...model.value, ...added].slice(0, props.max);
  // the same photo can be chosen again
  input.value = "";
};

const remove = (index: number) => {
  model.value = model.value.filter((_, position) => position !== index);
};

// small previews of the chosen files, released when they are no longer shown
const previews = ref<string[]>([]);
watch(
  model,
  (files) => {
    previews.value.forEach((url) => URL.revokeObjectURL(url));
    previews.value = files.map((file) => URL.createObjectURL(file));
  },
  { immediate: true },
);
onBeforeUnmount(() =>
  previews.value.forEach((url) => URL.revokeObjectURL(url)),
);
</script>
