<template>
  <Head title="Anmelden" />
  <h1 class="mb-6 text-xl font-semibold">Anmelden</h1>
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
      label="Passwort"
      type="password"
      autocomplete="current-password"
      required
      :error="form.errors.password"
    />
    <UiCheckbox v-model="form.remember" label="Angemeldet bleiben" />
    <UiButton type="submit" class="w-full" :disabled="form.processing">
      Anmelden
    </UiButton>
    <p class="text-center text-sm">
      <UiLink href="/forgot-password">Passwort vergessen?</UiLink>
    </p>
  </form>
</template>

<script setup lang="ts">
import { Head, useForm } from "@inertiajs/vue3";

const form = useForm({ email: "", password: "", remember: false });

const submit = () =>
  form.post("/login", { onFinish: () => form.reset("password") });
</script>
