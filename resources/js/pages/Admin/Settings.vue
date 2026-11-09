<template>
  <UiPageHeader title="Einstellungen" />

  <form class="space-y-6" @submit.prevent="submit">
    <UiCard title="Organisation">
      <div class="grid gap-5 sm:grid-cols-3">
        <UiInput
          v-model="form.organization.name"
          label="Name"
          required
          :error="form.errors['organization.name']"
        />
        <UiInput
          v-model="form.organization.short_name"
          label="Kurzname"
          required
          :error="form.errors['organization.short_name']"
        />
        <UiInput
          v-model="form.organization.accent_color"
          label="Akzentfarbe"
          type="color"
          hint="Wird nach dem Neuladen der Seite sichtbar."
          :error="form.errors['organization.accent_color']"
        />
      </div>
    </UiCard>

    <UiCard title="Rechtliches">
      <div class="grid gap-5 sm:grid-cols-3">
        <UiInput
          v-model="form.legal.imprint_url"
          label="Link zum Impressum"
          type="url"
          :error="form.errors['legal.imprint_url']"
        />
        <UiInput
          v-model="form.legal.privacy_url"
          label="Link zur Datenschutzerklärung"
          type="url"
          :error="form.errors['legal.privacy_url']"
        />
        <UiInput
          v-model="form.legal.contact_email"
          label="Kontaktadresse"
          type="email"
          :error="form.errors['legal.contact_email']"
        />
      </div>
    </UiCard>

    <UiCard
      title="E-Mail"
      description="Zugangsdaten zum Mailserver stehen in den Umgebungsvariablen."
    >
      <div class="grid gap-5 sm:grid-cols-2">
        <UiInput
          v-model="form.mail.from_name"
          label="Absendername"
          :error="form.errors['mail.from_name']"
        />
        <UiInput
          v-model="form.mail.from_address"
          label="Absenderadresse"
          type="email"
          :error="form.errors['mail.from_address']"
        />
      </div>
    </UiCard>

    <div class="flex justify-end">
      <UiButton type="submit" :disabled="form.processing">Speichern</UiButton>
    </div>
  </form>
</template>

<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";

const props = defineProps<{ settings: Record<string, string | null> }>();

const value = (key: string) => props.settings[key] ?? "";

const form = useForm({
  organization: {
    name: value("organization.name"),
    short_name: value("organization.short_name"),
    accent_color: value("organization.accent_color"),
  },
  legal: {
    imprint_url: value("legal.imprint_url"),
    privacy_url: value("legal.privacy_url"),
    contact_email: value("legal.contact_email"),
  },
  mail: {
    from_name: value("mail.from_name"),
    from_address: value("mail.from_address"),
  },
});

// empty fields are stored as null
const nullIfEmpty = (group: Record<string, string>) =>
  Object.fromEntries(
    Object.entries(group).map(([key, entry]) => [key, entry || null]),
  );

const submit = () =>
  form
    .transform((data) => ({
      ...data,
      legal: nullIfEmpty(data.legal),
      mail: nullIfEmpty(data.mail),
    }))
    .put("/admin/settings", { preserveScroll: true });
</script>
