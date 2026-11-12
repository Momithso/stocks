<template>
  <UiPageHeader title="Profil" :description="user.email" />

  <form class="space-y-6" @submit.prevent="submit">
    <UiCard title="Name">
      <div class="grid gap-5 sm:grid-cols-2">
        <UiInput
          v-model="form.firstname"
          label="Vorname"
          required
          :error="form.errors.firstname"
        />
        <UiInput
          v-model="form.lastname"
          label="Nachname"
          required
          :error="form.errors.lastname"
        />
      </div>
    </UiCard>

    <UiCard
      title="Passwort ändern"
      description="Leer lassen, um es zu behalten."
    >
      <div class="grid gap-5 sm:grid-cols-3">
        <UiInput
          v-model="form.current_password"
          label="Aktuelles Passwort"
          type="password"
          autocomplete="current-password"
          :error="form.errors.current_password"
        />
        <UiInput
          v-model="form.password"
          label="Neues Passwort"
          type="password"
          autocomplete="new-password"
          hint="Mindestens 12 Zeichen."
          :error="form.errors.password"
        />
        <UiInput
          v-model="form.password_confirmation"
          label="Wiederholen"
          type="password"
          autocomplete="new-password"
        />
      </div>
    </UiCard>

    <UiCard
      v-if="showPin"
      title="Kiosk-PIN"
      :description="
        hasPin
          ? 'Du hast eine PIN. Gib eine neue ein, um sie zu ändern.'
          : 'Mit der PIN entsperrst du den Kiosk am Tablet.'
      "
    >
      <div class="grid gap-5 sm:grid-cols-2">
        <UiInput
          v-model="form.pin"
          :label="`Neue PIN (${pinLength} Ziffern)`"
          type="password"
          inputmode="numeric"
          autocomplete="off"
          :error="form.errors.pin"
        />
        <UiInput
          v-model="form.pin_confirmation"
          label="PIN wiederholen"
          type="password"
          inputmode="numeric"
          autocomplete="off"
        />
      </div>
    </UiCard>

    <div class="flex justify-end">
      <UiButton type="submit" :disabled="form.processing">Speichern</UiButton>
    </div>
  </form>

  <UiCard
    v-if="notifications"
    title="Benachrichtigungen"
    description="Worüber Stocks dich per E-Mail oder Push informiert. Einladung und Passwort zurücksetzen kommen immer per E-Mail."
    class="mt-6"
  >
    <UiAlert v-if="pushError" type="error" class="mb-4">{{
      pushError
    }}</UiAlert>
    <p v-if="!notifications.events.length" class="text-sm text-ink-muted">
      Für deine Rechte gibt es noch keine Benachrichtigungen.
    </p>
    <NotificationsSettings
      v-else
      :events="notifications.events"
      :push-key="notifications.push_key"
      :push-devices="notifications.devices.length"
      scope="/"
      save-url="/profile/notifications"
      subscribe-url="/profile/push"
      unsubscribe-url="/profile/push"
    >
      <template #devices>
        <ul
          v-if="notifications.devices.length"
          class="divide-y divide-line rounded-md ring-1 ring-line"
        >
          <li
            v-for="device in notifications.devices"
            :key="device.id"
            class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm"
          >
            <span>
              <span class="font-medium">{{ device.device }}</span>
              <span class="block text-ink-muted">
                eingetragen <UiDateTime :value="device.created_at" />
                <template v-if="device.last_used_at">
                  · zuletzt <UiDateTime :value="device.last_used_at"
                /></template>
              </span>
            </span>
            <UiButton
              variant="ghost"
              size="sm"
              icon="xmark"
              @click="removeDevice(device.id)"
              >Entfernen</UiButton
            >
          </li>
        </ul>
      </template>
    </NotificationsSettings>
    <div class="mt-4 flex flex-wrap gap-2 border-t border-line pt-4">
      <UiButton
        variant="secondary"
        size="sm"
        icon="envelope"
        @click="sendTest('mail')"
        >Testmail senden</UiButton
      >
      <UiButton
        v-if="notifications.push_key"
        variant="secondary"
        size="sm"
        icon="bell"
        :disabled="!notifications.devices.length"
        @click="sendTest('push')"
        >Testbenachrichtigung senden</UiButton
      >
    </div>
  </UiCard>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { router, useForm, usePage } from "@inertiajs/vue3";
import type { NotificationRow } from "@/components/notifications/Settings.vue";

defineProps<{
  hasPin: boolean;
  showPin: boolean;
  pinLength: number;
  // null on the kiosk
  notifications: {
    events: NotificationRow[];
    devices: {
      id: number;
      device: string;
      created_at: string;
      last_used_at: string | null;
    }[];
    push_key: string | null;
  } | null;
}>();

const page = usePage<SharedProps>();
const user = computed(() => page.props.auth!.user);

const form = useForm({
  firstname: user.value.firstname,
  lastname: user.value.lastname,
  current_password: "",
  password: "",
  password_confirmation: "",
  pin: "",
  pin_confirmation: "",
});

const pushError = computed(() => page.props.errors?.push);
const removeDevice = (id: number) =>
  router.delete("/profile/push", { data: { id }, preserveScroll: true });
const sendTest = (channel: "mail" | "push") =>
  router.post("/profile/notify-test", { channel }, { preserveScroll: true });

const submit = () =>
  form
    .transform((data) => ({
      ...data,
      current_password: data.current_password || null,
      password: data.password || null,
      pin: data.pin || null,
    }))
    .put("/profile", {
      preserveScroll: true,
      onSuccess: () =>
        form.reset(
          "current_password",
          "password",
          "password_confirmation",
          "pin",
          "pin_confirmation",
        ),
    });
</script>
