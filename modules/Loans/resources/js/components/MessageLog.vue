<template>
  <UiCard
    id="nachrichten"
    title="Nachrichten"
    description="Was der Entleiher und der FSR per E-Mail und Push bekommen haben."
    flush
  >
    <template #actions>
      <UiButton
        variant="secondary"
        size="sm"
        icon="envelope"
        @click="open = true"
        >Erinnerung senden</UiButton
      >
    </template>
    <UiAlert v-if="!hasEmail" type="warning" class="m-4"
      >Die verantwortliche Person hat keine E-Mail-Adresse. Mails entfallen,
      Push geht trotzdem, falls eingeschaltet.</UiAlert
    >
    <ul v-if="log.length" class="divide-y divide-line text-sm">
      <li
        v-for="row in log"
        :key="row.id"
        class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-4 py-2 sm:px-6"
      >
        <span class="min-w-0">
          <span class="font-medium">{{ row.label }}</span>
          <span class="text-ink-muted">
            · {{ channelLabel(row.channel) }}
            <template v-if="row.channel !== 'trigger'">
              an {{ row.recipient }}</template
            >
            <template v-else> {{ row.recipient }}</template>
            <template v-if="row.sent_by"> von {{ row.sent_by }}</template>
          </span>
        </span>
        <span class="whitespace-nowrap text-xs text-ink-muted"
          ><UiDateTime :value="row.sent_at"
        /></span>
      </li>
    </ul>
    <UiEmptyState v-else icon="envelope" title="Noch keine Nachrichten" />
  </UiCard>

  <UiModal v-if="open" title="Erinnerung senden" @close="open = false">
    <form class="space-y-5" @submit.prevent="send">
      <UiSelect
        v-model="form.type"
        label="Nachricht"
        :options="reminders"
        :placeholder="null"
      />
      <p v-if="form.errors.type" class="text-sm text-red-600">
        {{ form.errors.type }}
      </p>
      <p class="text-sm text-ink-muted">
        Geht an den Entleiher, per E-Mail und Push nach den Einstellungen der
        Verleihseite.
      </p>
      <UiButton type="submit" class="w-full" :disabled="form.processing"
        >Senden</UiButton
      >
    </form>
  </UiModal>
</template>

<script setup lang="ts">
import { ref } from "vue";
import { useForm } from "@inertiajs/vue3";

export type LogRow = {
  id: number;
  type: string;
  label: string;
  channel: "mail" | "push" | "trigger";
  recipient: string;
  sent_by: string | null;
  sent_at: string;
};

/**
 * Log of the notifications of a loan (one row per receiver and channel) and sending a message by
 * hand.
 */
const props = defineProps<{
  loanId: number;
  log: LogRow[];
  reminders: Option[];
  hasEmail: boolean;
}>();

const open = ref(false);
const form = useForm({ type: props.reminders[0]?.value ?? "" });
const send = () =>
  form.post(`/loans/${props.loanId}/remind`, {
    preserveScroll: true,
    onSuccess: () => (open.value = false),
  });

const channelLabel = (channel: LogRow["channel"]) =>
  ({ mail: "E-Mail", push: "Push", trigger: "ausgelöst" })[channel];
</script>
