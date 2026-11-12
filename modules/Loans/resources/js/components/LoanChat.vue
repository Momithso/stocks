<template>
  <UiCard id="chat" title="Chat" flush>
    <template #actions>
      <UiBadge v-if="unread" variant="accent">{{ unread }} neu</UiBadge>
    </template>
    <LoansChatThread
      class="h-[28rem]"
      :messages="messages"
      :sending="sending"
      :error="error"
      empty-text="Noch keine Nachrichten. Der Entleiher schreibt auf der Verleihseite."
      @send="send"
    />
  </UiCard>
</template>

<script setup lang="ts">
import { ref } from "vue";
import LoansChatThread, { type ChatMessage } from "./ChatThread.vue";
import { getJson, sendJson } from "../http";

/**
 * Chat of a loan on the side of the organization. Polls every 5 seconds; while the page is open
 * the messages of the borrower count as read.
 */
const props = defineProps<{
  loanId: number;
  initial: ChatMessage[];
  initialUnread: number;
}>();

const messages = ref<ChatMessage[]>([...props.initial]);
const unread = ref(props.initialUnread);
const sending = ref(false);
const error = ref<string | null>(null);

const lastId = () => messages.value.at(-1)?.id ?? 0;
const append = (incoming: ChatMessage[]) => {
  const known = new Set(messages.value.map((message) => message.id));
  messages.value.push(...incoming.filter((message) => !known.has(message.id)));
};

const load = async () => {
  const data = await getJson<{ messages: ChatMessage[] }>(
    `/loans/${props.loanId}/messages?after=${lastId()}&read=1`,
  );
  if (data) {
    append(data.messages);
    unread.value = 0;
  }
};
// read at once: whoever opens the loan sees the messages
load();
usePolling(load, 5000);

const send = async (body: string) => {
  sending.value = true;
  error.value = null;
  const result = await sendJson<ChatMessage>(
    "POST",
    `/loans/${props.loanId}/messages`,
    { body },
  );
  sending.value = false;
  if (result.ok) {
    append([result.data]);
  } else {
    error.value = Object.values(result.errors)[0];
  }
};
</script>
