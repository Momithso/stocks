<template>
  <div class="flex min-h-0 flex-col">
    <div
      ref="scroller"
      class="min-h-0 flex-1 space-y-2 overflow-y-auto px-3 py-3 sm:px-4"
      aria-live="polite"
    >
      <p
        v-if="!messages.length"
        class="py-8 text-center text-sm text-ink-muted"
      >
        {{ emptyText }}
      </p>
      <template v-for="(message, index) in messages" :key="message.id">
        <p
          v-if="dayOf(message) !== dayOf(messages[index - 1])"
          class="py-1 text-center text-xs text-ink-muted"
        >
          <span class="rounded-full bg-surface-muted px-2 py-0.5">{{
            dayLabel(message.created_at)
          }}</span>
        </p>
        <p
          v-if="message.sender === 'system'"
          class="mx-auto max-w-[85%] text-center text-xs text-ink-muted"
        >
          {{ message.body }} · {{ timeOf(message.created_at) }}
        </p>
        <div
          v-else
          :class="['flex', message.mine ? 'justify-end' : 'justify-start']"
        >
          <div
            :class="[
              'max-w-[85%] rounded-2xl px-3 py-2 text-sm shadow-sm',
              message.mine
                ? 'rounded-br-sm bg-accent/15 text-ink'
                : 'rounded-bl-sm bg-surface-muted text-ink ring-1 ring-line/60',
            ]"
          >
            <p
              v-if="!message.mine"
              class="mb-0.5 text-xs font-semibold text-accent"
            >
              {{ message.author }}
            </p>
            <!-- text stays text: no html, links are not made clickable -->
            <p class="whitespace-pre-wrap break-words">{{ message.body }}</p>
            <p class="mt-0.5 text-right text-[0.65rem] text-ink-muted">
              {{ timeOf(message.created_at) }}
            </p>
          </div>
        </div>
      </template>
    </div>
    <form
      v-if="canSend"
      class="flex items-end gap-2 border-t border-line bg-surface p-2 sm:p-3"
      @submit.prevent="send"
    >
      <label class="sr-only" for="chat-input">Nachricht</label>
      <textarea
        id="chat-input"
        ref="input"
        v-model="body"
        rows="1"
        :maxlength="2000"
        placeholder="Nachricht"
        class="max-h-32 min-h-[44px] flex-1 resize-none rounded-2xl border-0 bg-surface-muted px-4 py-2.5 text-ink ring-1 ring-inset ring-line focus:ring-2 focus:ring-accent"
        @input="grow"
        @keydown.enter.exact.prevent="send"
      />
      <button
        type="submit"
        :disabled="!body.trim() || sending"
        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-accent text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
        aria-label="Senden"
      >
        <FontAwesomeIcon :icon="['fas', 'paper-plane']" />
      </button>
    </form>
    <p v-if="error" class="px-3 pb-2 text-sm text-red-600">{{ error }}</p>
  </div>
</template>

<script setup lang="ts">
import { nextTick, onMounted, ref, watch } from "vue";

export type ChatMessage = {
  id: number;
  sender: "contact" | "user" | "system";
  author: string;
  body: string;
  created_at: string;
  // written by the side that reads
  mine: boolean;
};

/**
 * Chat in the style of a messenger, for the borrower page and the loan page: own messages on the
 * right, others on the left, day separators, input at the bottom. The history scrolls in itself,
 * so the input stays visible. The page loads and sends; this only shows.
 */
const props = withDefaults(
  defineProps<{
    messages: ChatMessage[];
    canSend?: boolean;
    sending?: boolean;
    error?: string | null;
    emptyText?: string;
  }>(),
  {
    canSend: true,
    sending: false,
    error: null,
    emptyText: "Noch keine Nachrichten.",
  },
);

const emit = defineEmits<{ send: [body: string] }>();

const body = ref("");
const scroller = ref<HTMLElement | null>(null);
const input = ref<HTMLTextAreaElement | null>(null);

const toBottom = () =>
  nextTick(() => {
    if (scroller.value) {
      scroller.value.scrollTop = scroller.value.scrollHeight;
    }
  });
onMounted(toBottom);
watch(() => props.messages.length, toBottom);

const grow = () => {
  if (input.value) {
    input.value.style.height = "auto";
    input.value.style.height = `${input.value.scrollHeight}px`;
  }
};

const send = () => {
  const text = body.value.trim();
  if (!text || props.sending) {
    return;
  }
  emit("send", text);
  body.value = "";
  nextTick(grow);
};

const dayOf = (message?: ChatMessage) =>
  message ? new Date(message.created_at).toDateString() : null;

const dayLabel = (value: string) => {
  const date = new Date(value);
  const today = new Date();
  const yesterday = new Date();
  yesterday.setDate(today.getDate() - 1);
  if (date.toDateString() === today.toDateString()) {
    return "Heute";
  }
  if (date.toDateString() === yesterday.toDateString()) {
    return "Gestern";
  }
  return date.toLocaleDateString("de-DE", {
    weekday: "short",
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
  });
};

const timeOf = (value: string) =>
  new Date(value).toLocaleTimeString("de-DE", {
    hour: "2-digit",
    minute: "2-digit",
  });
</script>
