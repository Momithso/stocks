<template>
  <div class="space-y-5">
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase text-ink-muted">
            <th class="py-2 pr-4 font-medium">Ereignis</th>
            <th class="w-20 py-2 text-center font-medium">E-Mail</th>
            <th v-if="pushKey" class="w-20 py-2 text-center font-medium">
              Push
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-for="event in rows" :key="event.key">
            <td class="py-2 pr-4">
              <span class="font-medium">{{ event.label }}</span>
              <span v-if="event.description" class="block text-ink-muted">{{
                event.description
              }}</span>
            </td>
            <td class="py-2 text-center">
              <span
                v-if="event.required_mail"
                class="inline-flex flex-col items-center text-xs text-ink-muted"
              >
                <input
                  type="checkbox"
                  checked
                  disabled
                  :aria-label="`${event.label} per E-Mail, kommt immer`"
                  class="h-5 w-5 rounded border-line text-accent opacity-60"
                />
                kommt immer
              </span>
              <input
                v-else
                v-model="choices[event.key].mail"
                type="checkbox"
                :aria-label="`${event.label} per E-Mail`"
                class="h-5 w-5 rounded border-line bg-surface text-accent focus:ring-accent"
                @change="save"
              />
            </td>
            <td v-if="pushKey" class="py-2 text-center">
              <input
                v-model="choices[event.key].push"
                type="checkbox"
                :disabled="!pushDevices"
                :aria-label="`${event.label} per Push`"
                class="h-5 w-5 rounded border-line bg-surface text-accent focus:ring-accent disabled:opacity-40"
                @change="save"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-if="pushKey && !pushDevices" class="text-sm text-ink-muted">
      Push ist ausgegraut, solange auf keinem Gerät Push eingeschaltet ist.
      Siehe unten.
    </p>

    <div v-if="pushKey" class="space-y-3 border-t border-line pt-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm">
          Push auf diesem Gerät:
          <UiBadge :variant="stateBadge.variant">{{ stateBadge.text }}</UiBadge>
        </p>
        <UiButton
          v-if="push.state.value === 'on'"
          variant="secondary"
          size="sm"
          icon="bell-slash"
          :disabled="push.busy.value"
          @click="disable"
          >Push auf diesem Gerät ausschalten</UiButton
        >
        <UiButton
          v-else-if="['off', 'denied'].includes(push.state.value)"
          size="sm"
          icon="bell"
          class="min-h-[44px]"
          :disabled="push.busy.value"
          @click="enable"
          >Push auf diesem Gerät einschalten</UiButton
        >
      </div>
      <UiAlert v-if="push.error.value" type="error">{{
        push.error.value
      }}</UiAlert>
      <UiAlert v-if="push.state.value === 'ios-browser'" type="info">
        Am iPhone und iPad kommt Push nur in der App vom Home-Bildschirm an.
        Lege die Seite wie unten beschrieben auf den Home-Bildschirm und öffne
        sie von dort.
      </UiAlert>
      <UiAlert v-if="push.state.value === 'unsupported'" type="info">
        Dieser Browser kann keine Push-Nachrichten empfangen. Die E-Mails kommen
        trotzdem.
      </UiAlert>
      <slot name="devices" />
      <NotificationsPushGuide :state="push.state.value" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, watch, type PropType } from "vue";
import { router } from "@inertiajs/vue3";

export type NotificationRow = {
  key: string;
  label: string;
  group: string;
  description: string | null;
  required_mail: boolean;
  channels: { mail: boolean; push: boolean };
};

/**
 * Notification settings: one row per event with e-mail and push, and push on this device with the
 * instructions. Used in the profile and on the borrower page (documentation/NOTIFICATIONS.md).
 * Every change is saved at once.
 */
const props = defineProps({
  events: { type: Array as PropType<NotificationRow[]>, required: true },
  // null while push is off on the server: no push column, no button
  pushKey: { type: String, default: null },
  // devices of this account or page with push
  pushDevices: { type: Number, default: 0 },
  scope: { type: String, required: true },
  saveUrl: { type: String, required: true },
  subscribeUrl: { type: String, required: true },
  unsubscribeUrl: { type: String, required: true },
});

const rows = computed(() => props.events);
const choices = reactive<Record<string, { mail: boolean; push: boolean }>>({});
watch(
  () => props.events,
  (events) =>
    events.forEach((event) => (choices[event.key] = { ...event.channels })),
  { immediate: true },
);

const save = () =>
  router.put(
    props.saveUrl,
    { events: choices },
    { preserveScroll: true, preserveState: true },
  );

const push = usePush(props.scope, props.subscribeUrl, props.unsubscribeUrl);

const stateBadge = computed(
  () =>
    ({
      on: { text: "an", variant: "success" },
      off: { text: "aus", variant: "neutral" },
      denied: { text: "blockiert", variant: "danger" },
      unsupported: { text: "nicht möglich", variant: "neutral" },
      "ios-browser": { text: "nur vom Home-Bildschirm", variant: "warning" },
    })[push.state.value] as {
      text: string;
      variant: "success" | "neutral" | "danger" | "warning";
    },
);

const reload = () => router.reload({ preserveScroll: true } as never);
const enable = async () => {
  if (await push.enable(props.pushKey as string)) {
    reload();
  }
};
const disable = async () => {
  await push.disable();
  reload();
};
</script>
