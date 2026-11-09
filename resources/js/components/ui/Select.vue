<template>
  <div>
    <label :for="id" class="block text-sm font-medium">{{ label }}</label>
    <select
      :id="id"
      v-model="model"
      class="mt-1 block w-full rounded-md border-0 bg-surface py-2 text-ink shadow-sm ring-1 ring-inset ring-line focus:ring-2 focus:ring-accent sm:text-sm"
    >
      <option v-if="placeholder !== null" :value="null">
        {{ placeholder }}
      </option>
      <option
        v-for="option in options"
        :key="option.value"
        :value="option.value"
      >
        {{ option.label }}
      </option>
    </select>
  </div>
</template>

<script setup lang="ts">
import { useId, type PropType } from "vue";

/**
 * Select field with label.
 */
defineProps({
  label: { type: String, required: true },
  options: { type: Array as PropType<Option[]>, required: true },
  // entry for "nothing selected", null hides it
  placeholder: { type: String, default: "Alle" },
});

const model = defineModel<string | number | null>();
const id = useId();
</script>
