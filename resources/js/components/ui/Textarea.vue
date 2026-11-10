<template>
  <div>
    <label :for="id" class="block text-sm font-medium">
      {{ label }}<span v-if="required" class="text-red-600"> *</span>
    </label>
    <textarea
      :id="id"
      v-model="model"
      :rows="rows"
      :required="required"
      :placeholder="placeholder"
      :aria-invalid="!!error"
      :aria-describedby="error || hint ? `${id}-help` : undefined"
      :class="[
        'mt-1 block w-full rounded-md border-0 bg-surface py-2 text-ink shadow-sm ring-1 ring-inset placeholder:text-ink-muted focus:ring-2 focus:ring-inset focus:ring-accent sm:text-sm',
        error ? 'ring-red-500' : 'ring-line',
      ]"
    />
    <p
      v-if="error || hint"
      :id="`${id}-help`"
      :class="['mt-1 text-sm', error ? 'text-red-600' : 'text-ink-muted']"
    >
      {{ error ?? hint }}
    </p>
  </div>
</template>

<script setup lang="ts">
import { useId } from "vue";

/**
 * Text field with several lines, like UiInput.
 */
defineProps({
  label: { type: String, required: true },
  error: { type: String, default: null },
  hint: { type: String, default: null },
  required: { type: Boolean, default: false },
  placeholder: { type: String, default: null },
  rows: { type: Number, default: 3 },
});

const model = defineModel<string | null>();
const id = useId();
</script>
