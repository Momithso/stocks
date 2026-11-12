<template>
  <div class="space-y-2 text-sm">
    <div class="flex flex-wrap gap-1" role="tablist" aria-label="Gerät">
      <UiButton
        v-for="option in options"
        :key="option.value"
        size="sm"
        :variant="kind === option.value ? 'primary' : 'ghost'"
        :aria-selected="kind === option.value"
        role="tab"
        @click="kind = option.value"
      >
        {{ option.label }}
      </UiButton>
    </div>
    <ol class="list-decimal space-y-1 pl-5 text-ink-muted">
      <li v-for="step in steps[kind]" :key="step">{{ step }}</li>
    </ol>
    <p v-if="state === 'denied'" class="text-yellow-700 dark:text-yellow-300">
      Benachrichtigungen sind für diese Seite blockiert. Erlaube sie in den
      Einstellungen des Browsers (Schloss oder „i“ neben der Adresse) bzw. am
      iPhone unter Einstellungen › Mitteilungen und versuche es dann noch
      einmal.
    </p>
  </div>
</template>

<script setup lang="ts">
import { ref, type PropType } from "vue";
import {
  deviceKind,
  type DeviceKind,
  type PushState,
} from "@/composables/usePush";

/**
 * How to switch push on, for the device the browser reports (switchable). See
 * documentation/NOTIFICATIONS.md.
 */
const props = defineProps({
  state: { type: String as PropType<PushState>, default: "off" },
  // what the button is called on this page
  buttonLabel: { type: String, default: "Push auf diesem Gerät einschalten" },
});

const kind = ref<DeviceKind>(deviceKind());

const options: { value: DeviceKind; label: string }[] = [
  { value: "ios", label: "iPhone, iPad" },
  { value: "android", label: "Android" },
  { value: "desktop", label: "PC, Mac" },
];

const steps: Record<DeviceKind, string[]> = {
  ios: [
    "Ab iOS 16.4: in Safari auf „Teilen“ tippen, dann „Zum Home-Bildschirm“.",
    "Die App vom Home-Bildschirm öffnen (im normalen Safari-Tab geht Push am iPhone nicht).",
    `„${props.buttonLabel}“ tippen und erlauben.`,
  ],
  android: [
    `„${props.buttonLabel}“ tippen und erlauben (Chrome, Firefox, Samsung Internet).`,
    "Zum Startbildschirm hinzufügen geht auch, ist aber nicht nötig.",
  ],
  desktop: [
    `„${props.buttonLabel}“ klicken und erlauben (Chrome, Edge, Firefox, Safari ab macOS 13).`,
    "Der Browser muss laufen, damit die Nachricht ankommt.",
  ],
};
</script>
