<template>
  <Head title="Einladung annehmen" />
  <h1 class="mb-2 text-xl font-semibold">Willkommen!</h1>
  <p class="mb-6 text-sm text-ink-muted">
    Lege dein Konto für <strong class="text-ink">{{ email }}</strong> an.
  </p>
  <form class="space-y-5" @submit.prevent="submit">
    <div class="grid gap-5 sm:grid-cols-2">
      <UiInput
        v-model="form.firstname"
        label="Vorname"
        autocomplete="given-name"
        required
        :error="form.errors.firstname"
      />
      <UiInput
        v-model="form.lastname"
        label="Nachname"
        autocomplete="family-name"
        required
        :error="form.errors.lastname"
      />
    </div>
    <UiInput
      v-model="form.password"
      label="Passwort"
      type="password"
      autocomplete="new-password"
      hint="Mindestens 12 Zeichen. Ein Satz aus mehreren Wörtern ist sicher und leicht zu merken."
      required
      :error="form.errors.password"
    />
    <UiInput
      v-model="form.password_confirmation"
      label="Passwort wiederholen"
      type="password"
      autocomplete="new-password"
      required
    />
    <UiButton type="submit" class="w-full" :disabled="form.processing">
      Konto anlegen
    </UiButton>
  </form>
</template>

<script setup lang="ts">
import { Head, useForm } from "@inertiajs/vue3";

const props = defineProps<{ token: string; email: string }>();

const form = useForm({
  firstname: "",
  lastname: "",
  password: "",
  password_confirmation: "",
});

const submit = () =>
  form.post(`/invitation/${props.token}`, {
    onFinish: () => form.reset("password", "password_confirmation"),
  });
</script>
