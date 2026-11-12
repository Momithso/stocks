<template>
  <Head title="Kiosk" />
  <h1 class="text-2xl font-bold">Wer bist du?</h1>
  <p class="mb-6 text-ink-muted">
    Tippe auf deinen Namen und gib deine PIN ein. Danach wählst du, was du tun
    möchtest.
  </p>

  <!-- hint how to run the kiosk in full screen, as long as it runs in the browser -->
  <UiAlert v-if="!standalone" type="info" class="mb-6" :message="installHint" />

  <UiEmptyState
    v-if="!people.length"
    icon="lock"
    title="Niemand darf diesen Kiosk nutzen"
    description="Gib in der Rollenverwaltung einer Rolle das Recht „Sich am Kiosk mit PIN anmelden“."
  />

  <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
    <button
      v-for="person in people"
      :key="person.id"
      type="button"
      class="flex min-h-[120px] flex-col items-center justify-center gap-3 rounded-lg bg-surface p-4 shadow ring-1 ring-line/50 hover:ring-accent focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
      @click="select(person)"
    >
      <UiAvatar
        :firstname="person.firstname"
        :lastname="person.lastname"
        size="lg"
      />
      <span class="text-center font-medium"
        >{{ person.firstname }} {{ person.lastname }}</span
      >
    </button>
  </div>

  <UiModal
    v-if="selected"
    :title="`${selected.firstname} ${selected.lastname}`"
    @close="selected = null"
  >
    <UiAlert
      v-if="!selected.has_pin"
      type="info"
      message="Du hast noch keine PIN. Melde dich am Handy oder PC an und lege sie in deinem Profil fest."
    />
    <div v-else class="space-y-4">
      <UiAlert v-if="form.errors.pin" type="error" :message="form.errors.pin" />
      <UiPinPad
        ref="pinPad"
        :length="pinLength"
        :disabled="form.processing"
        @complete="unlock"
      />
    </div>
  </UiModal>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from "vue";
import { Head, useForm } from "@inertiajs/vue3";

type Person = {
  id: number;
  firstname: string;
  lastname: string;
  has_pin: boolean;
};

/**
 * Lock screen of the kiosk: choose your account, enter your pin.
 */
defineProps<{ people: Person[]; pinLength: number; installHint: string }>();

const standalone =
  window.matchMedia("(display-mode: standalone)").matches ||
  window.matchMedia("(display-mode: fullscreen)").matches;

const selected = ref<Person | null>(null);
const pinPad = ref<{ clear: () => void } | null>(null);
const form = useForm({ user_id: 0, pin: "" });

const select = (person: Person) => {
  form.clearErrors();
  selected.value = person;
};

const unlock = (pin: string) => {
  form.user_id = selected.value!.id;
  form.pin = pin;
  form.post("/kiosk/unlock", { onFinish: () => pinPad.value?.clear() });
};

// the dialog closes by itself if nobody types, so the next person sees the overview
let timeout: ReturnType<typeof setTimeout> | undefined;
const resetTimeout = () => {
  clearTimeout(timeout);
  timeout = setTimeout(() => (selected.value = null), 30000);
};
onMounted(() => window.addEventListener("pointerdown", resetTimeout));
onBeforeUnmount(() => {
  window.removeEventListener("pointerdown", resetTimeout);
  clearTimeout(timeout);
});
</script>
