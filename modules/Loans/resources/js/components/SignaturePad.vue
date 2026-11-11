<template>
  <div>
    <div class="flex items-center justify-between">
      <span class="text-sm font-medium">{{ label }}</span>
      <button
        type="button"
        class="text-sm text-ink-muted underline"
        @click="clear"
      >
        Neu
      </button>
    </div>
    <!-- white like paper in both colour modes, so the image looks the same on the pdf -->
    <canvas
      ref="canvas"
      class="mt-1 h-40 w-full touch-none rounded-md bg-white ring-1 ring-line"
      @pointerdown="start"
      @pointermove="draw"
      @pointerup="end"
      @pointerleave="end"
      @pointercancel="end"
    />
    <p class="mt-1 border-t border-dashed border-line pt-1 text-sm">
      {{ name }}
    </p>
    <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from "vue";

/**
 * Field to sign with finger, pen or mouse. The model is the signature as png data uri, null while
 * the field is empty.
 */
defineProps<{ label: string; name: string; error?: string | null }>();
const model = defineModel<string | null>({ default: null });

const canvas = ref<HTMLCanvasElement | null>(null);
let context: CanvasRenderingContext2D | null = null;
let drawing = false;

onMounted(() => {
  const element = canvas.value!;
  // the drawing buffer gets the size of the element, sharp on high density screens
  const ratio = window.devicePixelRatio || 1;
  element.width = element.clientWidth * ratio;
  element.height = element.clientHeight * ratio;
  context = element.getContext("2d");
  context?.scale(ratio, ratio);
  if (context) {
    context.lineWidth = 2.2;
    context.lineCap = "round";
    context.lineJoin = "round";
    context.strokeStyle = "#111";
  }
});

const point = (event: PointerEvent) => {
  const box = canvas.value!.getBoundingClientRect();
  return [event.clientX - box.left, event.clientY - box.top] as const;
};

const start = (event: PointerEvent) => {
  drawing = true;
  canvas.value?.setPointerCapture(event.pointerId);
  const [x, y] = point(event);
  context?.beginPath();
  context?.moveTo(x, y);
};

const draw = (event: PointerEvent) => {
  if (!drawing || !context) {
    return;
  }
  const [x, y] = point(event);
  context.lineTo(x, y);
  context.stroke();
};

const end = () => {
  if (!drawing) {
    return;
  }
  drawing = false;
  model.value = canvas.value?.toDataURL("image/png") ?? null;
};

const clear = () => {
  const element = canvas.value!;
  context?.clearRect(0, 0, element.width, element.height);
  model.value = null;
};
</script>
