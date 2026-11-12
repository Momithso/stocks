<template>
  <Head :title="`Ausleihe ${state.number}`">
    <meta name="robots" content="noindex, nofollow" />
    <meta name="apple-mobile-web-app-title" :content="state.number" />
  </Head>

  <UiFlash />

  <!-- head: what, status, one sentence, progress -->
  <UiCard>
    <div class="flex flex-wrap items-start justify-between gap-2">
      <div class="min-w-0">
        <h1 class="break-words text-xl font-bold tracking-tight">
          {{ state.purpose ?? state.event ?? "Ausleihe" }}
        </h1>
        <p class="font-mono text-sm text-ink-muted">{{ state.number }}</p>
      </div>
      <UiBadge :variant="state.status_variant">{{
        state.status_label
      }}</UiBadge>
    </div>
    <p class="mt-3 font-medium">{{ state.sentence }}</p>
    <ol
      v-if="state.step >= 0"
      class="mt-4 grid grid-cols-4 gap-1 text-center text-xs"
      aria-label="Fortschritt"
    >
      <li v-for="(step, index) in steps" :key="step" class="space-y-1">
        <span
          :class="[
            'block h-1.5 rounded-full',
            index <= state.step ? 'bg-accent' : 'bg-surface-muted',
          ]"
        />
        <span
          :class="index <= state.step ? 'font-medium' : 'text-ink-muted'"
          :aria-current="index === state.step ? 'step' : undefined"
          >{{ step }}</span
        >
      </li>
    </ol>
  </UiCard>

  <!-- tabs, they stay on top while the page scrolls -->
  <nav
    class="sticky top-0 z-10 -mx-4 mt-4 bg-page/95 px-4 py-2 backdrop-blur"
    aria-label="Bereiche"
  >
    <div
      class="grid grid-cols-3 gap-1 rounded-lg bg-surface p-1 shadow ring-1 ring-line/50"
      role="tablist"
    >
      <button
        v-for="entry in tabs"
        :key="entry.key"
        type="button"
        role="tab"
        :aria-selected="tab === entry.key"
        :class="[
          'relative flex min-h-[44px] items-center justify-center gap-2 rounded-md text-sm font-medium transition-colors',
          tab === entry.key
            ? 'bg-accent text-white'
            : 'text-ink-muted hover:bg-surface-muted hover:text-ink',
        ]"
        @click="open(entry.key)"
      >
        <FontAwesomeIcon :icon="['fas', entry.icon]" />{{ entry.label }}
        <span
          v-if="entry.key === 'chat' && state.unread && tab !== 'chat'"
          class="absolute right-1.5 top-1.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-xs text-white"
          :aria-label="`${state.unread} ungelesen`"
          >{{ state.unread }}</span
        >
      </button>
    </div>
  </nav>

  <!-- overview -->
  <div v-if="tab === 'overview'" class="mt-2 space-y-4">
    <UiAlert v-if="state.status === 'overdue'" type="error">
      Die Rückgabe war am {{ formatDateTime(state.return_at) }}. Bitte bring das
      Material zurück oder schreib uns im Chat.
    </UiAlert>
    <UiAlert v-if="state.status === 'unchecked'" type="info">
      Das Material wurde ungeprüft angenommen, Mängel vorbehalten. Wir prüfen es
      noch und melden uns, falls etwas ist.
    </UiAlert>
    <UiAlert v-if="state.status === 'cancelled'" type="warning">
      Storniert<template v-if="state.cancelled_reason"
        >: {{ state.cancelled_reason }}</template
      >
    </UiAlert>

    <UiCard v-if="state.status !== 'cancelled'" title="Termine">
      <dl class="grid gap-3 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-ink-muted">Abholung</dt>
          <dd class="font-medium">{{ formatDateTime(state.pickup_at) }}</dd>
        </div>
        <div>
          <dt class="text-ink-muted">Rückgabe</dt>
          <dd class="font-medium">{{ formatDateTime(state.return_at) }}</dd>
        </div>
        <div v-if="state.place" class="sm:col-span-2">
          <dt class="text-ink-muted">Ort</dt>
          <dd>{{ state.place }}</dd>
        </div>
      </dl>
      <UiAlert
        v-if="state.extension?.status === 'open'"
        type="info"
        class="mt-4"
      >
        Verlängerung bis
        {{ formatDateTime(state.extension.requested_return_at) }} angefragt. Der
        FSR entscheidet bald.
      </UiAlert>
      <UiAlert
        v-else-if="
          state.extension &&
          ['accepted', 'declined'].includes(state.extension.status)
        "
        :type="state.extension.status === 'accepted' ? 'success' : 'warning'"
        class="mt-4"
      >
        Verlängerung
        {{ state.extension.status === "accepted" ? "angenommen" : "abgelehnt"
        }}<template v-if="state.extension.answer"
          >: {{ state.extension.answer }}</template
        >
      </UiAlert>
      <UiButton
        v-if="state.can_extend"
        variant="secondary"
        icon="clock"
        class="mt-4 min-h-[44px]"
        @click="extensionOpen = true"
        >Verlängerung anfragen</UiButton
      >
    </UiCard>

    <UiCard v-if="state.pack_pin" title="Pack-PIN">
      <div class="flex items-center gap-2">
        <span
          class="whitespace-nowrap font-mono text-2xl font-semibold tracking-widest"
          >{{ pinVisible ? state.pack_pin : "••••••" }}</span
        >
        <UiButton
          variant="ghost"
          size="sm"
          class="min-h-[44px] min-w-[44px]"
          :icon="pinVisible ? 'eye-slash' : 'eye'"
          :aria-label="pinVisible ? 'PIN verbergen' : 'PIN anzeigen'"
          @click="pinVisible = !pinVisible"
        />
        <UiButton
          variant="ghost"
          size="sm"
          class="min-h-[44px] min-w-[44px]"
          icon="pen"
          aria-label="PIN ändern"
          @click="pinOpen = true"
        />
      </div>
      <p class="mt-1 text-sm text-ink-muted">
        Mit der PIN bestätigst du auf der Seite einer Box (QR-Code auf der Box),
        dass du ihren Inhalt kontrolliert hast.
      </p>
    </UiCard>

    <UiCard v-if="documents.length" title="Dokumente" flush>
      <ul class="divide-y divide-line">
        <li v-for="document in documents" :key="document.id">
          <a
            :href="document.url"
            class="flex min-h-[44px] items-center gap-3 px-4 py-3 hover:bg-surface-muted sm:px-6"
          >
            <FontAwesomeIcon
              :icon="['fas', 'file-pdf']"
              class="h-5 w-5 text-ink-muted"
            />
            <span class="min-w-0 flex-1">
              <span class="block font-medium text-accent">{{
                document.label
              }}</span>
              <span class="text-xs text-ink-muted"
                ><UiDateTime :value="document.created_at"
              /></span>
            </span>
            <FontAwesomeIcon
              :icon="['fas', 'download']"
              class="text-ink-muted"
            />
          </a>
        </li>
      </ul>
    </UiCard>

    <UiCard v-if="defects.length" title="Mängel" flush>
      <ul class="divide-y divide-line text-sm">
        <li
          v-for="defect in defects"
          :key="defect.number"
          class="px-4 py-3 sm:px-6"
        >
          <p class="font-medium">{{ defect.name }}</p>
          <p class="text-ink-muted">
            <span class="font-mono">{{ defect.number }}</span> ·
            {{ defect.state
            }}<template v-if="defect.note"> · {{ defect.note }}</template>
          </p>
        </li>
      </ul>
    </UiCard>

    <UiCard title="Kontakte">
      <div class="grid gap-5 text-sm sm:grid-cols-2">
        <div v-for="person in people" :key="person.role" class="space-y-0.5">
          <p class="text-ink-muted">{{ person.role }}</p>
          <p class="font-medium">
            {{ person.name
            }}<template v-if="person.affiliation">
              ({{ person.affiliation }})</template
            >
          </p>
          <UiContactLink kind="phone" :value="person.phone" />
          <UiContactLink kind="email" :value="person.email" />
          <p v-if="person.address">{{ person.address }}</p>
        </div>
        <div class="space-y-0.5">
          <p class="text-ink-muted">Verleiher</p>
          <p class="font-medium">{{ contacts.organization.name }}</p>
          <UiContactLink kind="phone" :value="contacts.organization.phone" />
          <UiContactLink kind="email" :value="contacts.organization.email" />
          <p v-if="contacts.members.length" class="text-ink-muted">
            Beteiligt: {{ contacts.members.join(", ") }}
          </p>
        </div>
      </div>
    </UiCard>

    <UiCard
      id="benachrichtigungen"
      title="Benachrichtigungen"
      description="Gilt für alle mit diesem Link. Ob Push ankommt, entscheidet jedes Gerät selbst."
    >
      <NotificationsSettings
        :events="notifications.events"
        :push-key="notifications.push_key"
        :push-devices="notifications.push_devices"
        :scope="base"
        :save-url="`${base}/notifications`"
        :subscribe-url="`${base}/push`"
        :unsubscribe-url="`${base}/push`"
      />
    </UiCard>
  </div>

  <!-- material with checklist -->
  <div v-else-if="tab === 'material'" class="mt-2 space-y-4">
    <UiCard flush>
      <div
        class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3 sm:px-6"
      >
        <p v-if="state.checklist" class="text-sm font-medium">
          {{ checkedCount }} von {{ checkable.length }} abgehakt
        </p>
        <p v-else class="text-sm text-ink-muted">
          Abhaken geht ab der Ausgabe.
        </p>
        <UiButton
          v-if="state.checklist && checkedCount"
          variant="ghost"
          size="sm"
          icon="rotate-left"
          @click="tick(null, false, true)"
          >zurücksetzen</UiButton
        >
      </div>
      <ul class="divide-y divide-line">
        <li v-for="(row, index) in visibleRows" :key="row.id ?? `r${index}`">
          <div
            class="flex min-h-[44px] items-center gap-3 py-2 pr-4 sm:pr-6"
            :style="{ paddingLeft: `${1 + row.depth * 1.25}rem` }"
          >
            <input
              v-if="state.checklist && row.id"
              type="checkbox"
              :checked="row.checked"
              :aria-label="`${row.name} abhaken`"
              class="h-6 w-6 shrink-0 rounded border-line bg-surface text-accent focus:ring-accent"
              @change="
                tick(row.id, ($event.target as HTMLInputElement).checked)
              "
            />
            <span class="min-w-0 flex-1">
              <span
                :class="[
                  'block break-words',
                  row.depth === 0 ? 'font-medium' : '',
                  row.checked ? 'text-ink-muted line-through' : '',
                ]"
                >{{ row.name }}</span
              >
              <span class="text-xs text-ink-muted">
                <span class="font-mono">{{ row.number }}</span
                ><template v-if="row.amount"> · {{ row.amount }}</template>
              </span>
            </span>
            <UiButton
              v-if="row.is_container && hasChildren(row)"
              variant="ghost"
              size="sm"
              class="min-h-[44px] min-w-[44px]"
              :icon="collapsed.has(row.id!) ? 'chevron-right' : 'chevron-down'"
              :aria-label="
                collapsed.has(row.id!) ? 'Inhalt zeigen' : 'Inhalt verbergen'
              "
              @click="toggle(row.id!)"
            />
          </div>
        </li>
      </ul>
    </UiCard>
    <p class="px-1 text-sm text-ink-muted">
      Der Stand gilt für alle mit diesem Link. Die Liste ist eine Hilfe beim
      Packen: die Rückgabe zählt erst, wenn der FSR sie annimmt.
    </p>
  </div>

  <!-- chat: fills the screen, the history scrolls in itself -->
  <div v-else class="mt-2">
    <UiAlert
      v-if="notifications.push_key && !notifications.push_devices"
      type="info"
      class="mb-3"
    >
      Antworten bekommst du per E-Mail.
      <button
        type="button"
        class="font-bold underline"
        @click="openNotifications"
      >
        Push einschalten
      </button>
    </UiAlert>
    <UiCard flush class="overflow-hidden">
      <LoansChatThread
        ref="chatBox"
        class="min-h-[20rem]"
        :style="{ height: chatHeight }"
        :messages="messages"
        :sending="sending"
        :error="chatError"
        :can-send="state.status !== 'cancelled'"
        empty-text="Schreib uns, wenn du eine Frage hast oder sich etwas ändert."
        @send="send"
      />
    </UiCard>
  </div>

  <UiModal
    v-if="extensionOpen"
    title="Verlängerung anfragen"
    @close="extensionOpen = false"
  >
    <form class="space-y-5" @submit.prevent="requestExtension">
      <UiInput
        v-model="extensionForm.return_at"
        label="Gewünschte Rückgabe"
        type="datetime-local"
        required
        :error="extensionForm.errors.return_at"
      />
      <UiTextarea
        v-model="extensionForm.note"
        label="Notiz (freiwillig)"
        :error="extensionForm.errors.note"
      />
      <p class="text-sm text-ink-muted">
        Der FSR prüft, ob das Material bis dahin frei ist, und sagt dir
        Bescheid.
      </p>
      <UiButton
        type="submit"
        class="w-full min-h-[44px]"
        :disabled="extensionForm.processing"
        >Anfragen</UiButton
      >
    </form>
  </UiModal>

  <UiModal v-if="pinOpen" title="Pack-PIN ändern" @close="pinOpen = false">
    <form class="space-y-5" @submit.prevent="changePin">
      <UiInput
        v-model="pinForm.pin"
        label="Neue PIN (sechs Ziffern)"
        inputmode="numeric"
        autocomplete="off"
        maxlength="6"
        required
        :error="pinForm.errors.pin"
      />
      <UiButton
        type="submit"
        class="w-full min-h-[44px]"
        :disabled="pinForm.processing"
        >Speichern</UiButton
      >
    </form>
  </UiModal>
</template>

<script setup lang="ts">
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  reactive,
  ref,
  watch,
} from "vue";
import { Head, useForm } from "@inertiajs/vue3";
import PublicLayout from "@/layouts/PublicLayout.vue";
import type { NotificationRow } from "@/components/notifications/Settings.vue";
import LoansChatThread, {
  type ChatMessage,
} from "../../components/ChatThread.vue";
import { formatDateTime, getJson, sendJson } from "../../http";

defineOptions({ layout: PublicLayout });

type State = {
  number: string;
  purpose: string | null;
  event: string | null;
  status: string;
  status_label: string;
  status_variant: "neutral" | "accent" | "success" | "warning" | "danger";
  sentence: string;
  step: number;
  pickup_at: string;
  return_at: string;
  place: string | null;
  cancelled_reason: string | null;
  pack_pin: string | null;
  can_extend: boolean;
  extension: {
    status: "open" | "accepted" | "declined" | "done";
    requested_return_at: string;
    answer: string | null;
  } | null;
  checklist: boolean;
  unread: number;
};

type Row = {
  id: number | null;
  parent_id: number | null;
  depth: number;
  number: string;
  name: string;
  amount: string | null;
  is_container: boolean;
  checked: boolean;
};

type Person = {
  name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  affiliation: string | null;
};

/**
 * Borrower page of a loan, without login (documentation/LOANS.md): tabs overview, material with
 * checklist and chat. Polls every 5 seconds, so two phones see the same state.
 */
const props = defineProps<{
  loan: State;
  contacts: {
    responsible: Person | null;
    borrower: Person | null;
    organization: { name: string; phone: string | null; email: string | null };
    members: string[];
  };
  documents: { id: number; label: string; created_at: string; url: string }[];
  defects: {
    name: string;
    number: string;
    state: string;
    note: string | null;
  }[];
  rows: Row[];
  messages: ChatMessage[];
  notifications: {
    events: NotificationRow[];
    push_key: string | null;
    push_devices: number;
  };
  base: string;
  tab: "overview" | "material" | "chat";
}>();

const steps = ["Bestätigt", "Abgeholt", "Zurück", "Geprüft"];
const tabs = [
  { key: "chat", label: "Chat", icon: "comments" },
  { key: "overview", label: "Übersicht", icon: "circle-info" },
  { key: "material", label: "Material", icon: "list-check" },
] as const;

const tab = ref(props.tab);
const state = ref<State>(props.loan);
const rows = ref<Row[]>(props.rows);
const messages = ref<ChatMessage[]>([...props.messages]);

// a redirect back (extension, pin, settings) brings new props
watch(
  () => props.loan,
  (loan) => (state.value = loan),
);
watch(
  () => props.rows,
  (value) => (rows.value = value),
);

const open = (key: typeof tab.value) => {
  tab.value = key;
  const url = new URL(window.location.href);
  url.searchParams.set("tab", key);
  window.history.replaceState(window.history.state, "", url);
  if (key === "chat") {
    poll();
  }
};
const openNotifications = async () => {
  open("overview");
  await nextTick();
  document.getElementById("benachrichtigungen")?.scrollIntoView();
};

const people = computed(() =>
  [
    { role: "Verantwortlich", ...props.contacts.responsible },
    props.contacts.borrower
      ? { role: "Entleiher", ...props.contacts.borrower }
      : null,
  ].filter((person): person is Person & { role: string } =>
    Boolean(person?.name),
  ),
);

// checklist
const checkable = computed(() => rows.value.filter((row) => row.id));
const checkedCount = computed(
  () => checkable.value.filter((row) => row.checked).length,
);
const collapsed = reactive(new Set<number>());
const hasChildren = (row: Row) =>
  rows.value.some((other) => other.parent_id === row.id);
const toggle = (id: number) =>
  collapsed.has(id) ? collapsed.delete(id) : collapsed.add(id);
const hidden = (row: Row): boolean => {
  const parent = rows.value.find((other) => other.id === row.parent_id);
  return parent ? collapsed.has(parent.id!) || hidden(parent) : false;
};
const visibleRows = computed(() => rows.value.filter((row) => !hidden(row)));

const tick = async (row: number | null, checked: boolean, reset = false) => {
  const result = await sendJson<{ rows: Row[] }>(
    "POST",
    `${props.base}/ticks`,
    {
      row,
      checked,
      reset,
    },
  );
  if (result.ok) {
    rows.value = result.data.rows;
  }
};

// chat
const sending = ref(false);
const chatError = ref<string | null>(null);
const append = (incoming: ChatMessage[]) => {
  const known = new Set(messages.value.map((message) => message.id));
  messages.value.push(...incoming.filter((message) => !known.has(message.id)));
};
const send = async (body: string) => {
  sending.value = true;
  chatError.value = null;
  const result = await sendJson<ChatMessage>("POST", `${props.base}/messages`, {
    body,
  });
  sending.value = false;
  if (result.ok) {
    append([result.data]);
  } else {
    chatError.value = Object.values(result.errors)[0];
  }
};

// the chat fills the rest of the screen, so the input stays at the bottom
const chatBox = ref<{ $el: HTMLElement } | null>(null);
const chatHeight = ref("70vh");
const fitChat = () => {
  const top = chatBox.value?.$el.getBoundingClientRect().top;
  if (top !== undefined) {
    chatHeight.value = `${Math.max(320, window.innerHeight - Math.max(top, 0) - 16)}px`;
  }
};
watch(tab, () => nextTick(fitChat), { immediate: true });
onMounted(() => window.addEventListener("resize", fitChat));
onBeforeUnmount(() => window.removeEventListener("resize", fitChat));

const poll = async () => {
  const after = messages.value.at(-1)?.id ?? 0;
  const data = await getJson<{
    loan: State;
    messages: ChatMessage[];
    rows: Row[];
  }>(`${props.base}/poll?after=${after}&read=${tab.value === "chat" ? 1 : 0}`);
  if (data) {
    state.value = data.loan;
    append(data.messages);
    rows.value = data.rows;
  }
};
usePolling(poll, 5000);

// extension and pin
const extensionOpen = ref(false);
const extensionForm = useForm({
  return_at: props.loan.return_at.slice(0, 16),
  note: "",
});
const requestExtension = () =>
  extensionForm.post(`${props.base}/extension`, {
    preserveScroll: true,
    onSuccess: () => (extensionOpen.value = false),
  });

const pinVisible = ref(false);
const pinOpen = ref(false);
const pinForm = useForm({ pin: "" });
const changePin = () =>
  pinForm.post(`${props.base}/pin`, {
    preserveScroll: true,
    onSuccess: () => {
      pinOpen.value = false;
      pinVisible.value = true;
      pinForm.reset();
    },
  });
</script>
