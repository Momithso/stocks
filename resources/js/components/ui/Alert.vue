<template>
  <div
    :class="['flex gap-3 rounded-md p-4 text-sm', styles[type].box]"
    role="status"
  >
    <FontAwesomeIcon
      :icon="['fas', styles[type].icon]"
      :class="['mt-0.5 h-4 w-4 shrink-0', styles[type].icon_color]"
    />
    <div class="font-medium">
      <slot>{{ message }}</slot>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { PropType } from "vue";

/**
 * Message box for info, success, warning and error. Messages are shown as text, never as html.
 */
defineProps({
  type: {
    type: String as PropType<"info" | "success" | "warning" | "error">,
    default: "info",
  },
  message: { type: String, default: null },
});

const styles = {
  info: {
    box: "bg-blue-50 text-blue-800 dark:bg-blue-950 dark:text-blue-100",
    icon: "circle-info",
    icon_color: "text-blue-500",
  },
  success: {
    box: "bg-green-50 text-green-800 dark:bg-green-950 dark:text-green-100",
    icon: "circle-check",
    icon_color: "text-green-500",
  },
  warning: {
    box: "bg-yellow-50 text-yellow-800 dark:bg-yellow-950 dark:text-yellow-100",
    icon: "triangle-exclamation",
    icon_color: "text-yellow-500",
  },
  error: {
    box: "bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-100",
    icon: "circle-xmark",
    icon_color: "text-red-500",
  },
};
</script>
