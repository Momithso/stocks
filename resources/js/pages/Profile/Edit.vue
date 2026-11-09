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
      v-if="canUseKiosk"
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
</template>

<script setup lang="ts">
import { computed } from "vue";
import { useForm, usePage } from "@inertiajs/vue3";

defineProps<{ hasPin: boolean; canUseKiosk: boolean; pinLength: number }>();

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
