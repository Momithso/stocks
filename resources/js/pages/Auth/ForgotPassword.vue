<template>
  <Head title="Passwort vergessen" />
  <h1 class="mb-2 text-xl font-semibold">Passwort vergessen</h1>
  <p class="mb-6 text-sm text-ink-muted">
    Gib deine E-Mail-Adresse ein. Wir schicken dir einen Link, mit dem du ein
    neues Passwort festlegst.
  </p>
  <form class="space-y-5" @submit.prevent="form.post('/forgot-password')">
    <UiInput
      v-model="form.email"
      label="E-Mail-Adresse"
      type="email"
      autocomplete="username"
      required
      :error="form.errors.email"
    />
    <UiButton type="submit" class="w-full" :disabled="form.processing">
      Link schicken
    </UiButton>
    <p class="text-center text-sm">
      <UiLink href="/login">Zurück zur Anmeldung</UiLink>
    </p>
  </form>
</template>

<script setup lang="ts">
import { Head, useForm } from "@inertiajs/vue3";

const form = useForm({ email: "" });
</script>
