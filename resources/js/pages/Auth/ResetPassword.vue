<template>
  <Head title="Neues Passwort" />
  <h1 class="mb-6 text-xl font-semibold">Neues Passwort festlegen</h1>
  <form class="space-y-5" @submit.prevent="submit">
    <UiInput
      v-model="form.email"
      label="E-Mail-Adresse"
      type="email"
      autocomplete="username"
      required
      :error="form.errors.email"
    />
    <UiInput
      v-model="form.password"
      label="Neues Passwort"
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
      Passwort speichern
    </UiButton>
  </form>
</template>

<script setup lang="ts">
import { Head, useForm } from "@inertiajs/vue3";

const props = defineProps<{ token: string; email: string }>();

const form = useForm({
  token: props.token,
  email: props.email,
  password: "",
  password_confirmation: "",
});

const submit = () =>
  form.post("/reset-password", {
    onFinish: () => form.reset("password", "password_confirmation"),
  });
</script>
