<template>
  <label
    :class="[
      'flex items-start gap-3',
      disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer',
    ]"
  >
    <input
      v-model="model"
      type="checkbox"
      :value="value"
      :disabled="disabled"
      class="mt-0.5 h-4 w-4 rounded border-line bg-surface text-accent focus:ring-accent"
    />
    <span class="text-sm">
      <span class="font-medium">{{ label }}</span>
      <span v-if="description" class="block text-ink-muted">
        {{ description }}
      </span>
    </span>
  </label>
</template>

<script setup lang="ts">
/**
 * Checkbox with label. With "value" the model is the array of the checked values.
 */
defineProps({
  label: { type: String, required: true },
  description: { type: String, default: null },
  value: { type: [String, Number], default: undefined },
  disabled: { type: Boolean, default: false },
});

const model = defineModel<boolean | Array<string | number>>();
</script>
