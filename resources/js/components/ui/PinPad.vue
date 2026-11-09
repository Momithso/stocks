<template>
  <div class="flex flex-col gap-6">
    <div class="flex justify-center gap-4">
      <div
        v-for="index in length"
        :key="index"
        :class="[
          index <= pin.length ? 'bg-accent' : 'bg-line',
          'h-4 w-4 rounded-full',
        ]"
      />
    </div>

    <!-- no AppButton: touchscreens keep the hover state of the last tapped key, which would show the pin to others -->
    <div class="grid grid-cols-3 gap-3">
      <button
        v-for="digit in ['1', '2', '3', '4', '5', '6', '7', '8', '9']"
        :key="digit"
        type="button"
        :class="keyClass"
        :disabled="disabled"
        @click="addDigit(digit, $event)"
      >
        {{ digit }}
      </button>
      <div />
      <button
        type="button"
        :class="keyClass"
        :disabled="disabled"
        @click="addDigit('0', $event)"
      >
        0
      </button>
      <button
        type="button"
        :class="keyClass"
        :disabled="disabled || !pin.length"
        aria-label="Letzte Ziffer löschen"
        @click="removeDigit($event)"
      >
        <FontAwesomeIcon :icon="['fas', 'delete-left']" />
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from "vue";

const props = defineProps({
  length: {
    type: Number,
    default: 6,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits<{
  (e: "complete", pin: string): void;
}>();

const pin = ref("");

// only highlighted while pressed (active), never by hover or focus
const keyClass =
  "min-h-[56px] touch-manipulation select-none rounded-md bg-surface-muted py-4 text-center text-2xl font-semibold text-ink ring-1 ring-inset ring-line focus:outline-none active:bg-accent active:text-white disabled:cursor-not-allowed disabled:opacity-50";

// a tapped button keeps the focus, which some browsers show
const releaseKey = (event: MouseEvent) => {
  (event.currentTarget as HTMLElement | null)?.blur();
};

const addDigit = (digit: string, event: MouseEvent) => {
  releaseKey(event);

  if (props.disabled || pin.value.length >= props.length) {
    return;
  }

  pin.value += digit;

  if (pin.value.length === props.length) {
    emit("complete", pin.value);
  }
};

const removeDigit = (event: MouseEvent) => {
  releaseKey(event);
  pin.value = pin.value.slice(0, -1);
};

const clear = () => {
  pin.value = "";
};

defineExpose({ clear });
</script>
