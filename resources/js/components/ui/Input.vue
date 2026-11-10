<template>
  <div>
    <label :for="id" class="block text-sm font-medium">
      {{ label }}<span v-if="required" class="text-red-600"> *</span>
    </label>
    <input
      :id="id"
      v-model="model"
      :type="type"
      :name="name"
      :required="required"
      :autocomplete="autocomplete"
      :inputmode="inputmode"
      :step="step"
      :placeholder="placeholder"
      :aria-invalid="!!error"
      :aria-describedby="error || hint ? `${id}-help` : undefined"
      :class="[
        'mt-1 block w-full rounded-md border-0 bg-surface py-2 text-ink shadow-sm ring-1 ring-inset placeholder:text-ink-muted focus:ring-2 focus:ring-inset focus:ring-accent sm:text-sm',
        error ? 'ring-red-500' : 'ring-line',
        type === 'color' ? 'h-10 p-1' : '',
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
 * Text field with label and error message (e.g. form.errors.email of Inertia's useForm).
 */
defineProps({
  label: { type: String, required: true },
  type: { type: String, default: "text" },
  name: { type: String, default: null },
  error: { type: String, default: null },
  hint: { type: String, default: null },
  required: { type: Boolean, default: false },
  autocomplete: { type: String, default: null },
  inputmode: { type: String, default: null },
  placeholder: { type: String, default: null },
  // number fields: "0.5" allows halves, "any" all decimals
  step: { type: String, default: null },
});

const model = defineModel<string | number | null>();
const id = useId();
</script>
