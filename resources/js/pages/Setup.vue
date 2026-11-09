<template>
  <Head title="Einrichtung" />
  <h1 class="mb-2 text-xl font-semibold">Stocks einrichten</h1>
  <p class="mb-6 text-sm text-ink-muted">
    Alles außer dem Admin-Konto lässt sich später in den Einstellungen ändern.
  </p>

  <UiAlert v-if="!hasToken" type="error" class="mb-6">
    Es ist kein Einrichtungsschlüssel gesetzt. Trage ihn als Umgebungsvariable
    SETUP_TOKEN ein und lade die Seite neu, oder richte Stocks mit
    <code>php artisan stocks:install</code> ein.
  </UiAlert>

  <form v-else class="space-y-8" @submit.prevent="submit">
    <fieldset class="space-y-4">
      <legend class="font-semibold">1. Zugang</legend>
      <UiInput
        v-model="form.token"
        label="Einrichtungsschlüssel"
        type="password"
        hint="Steht in der Umgebungsvariable SETUP_TOKEN des Servers."
        required
        :error="form.errors.token"
      />
    </fieldset>

    <fieldset class="space-y-4">
      <legend class="font-semibold">2. Organisation</legend>
      <UiInput
        v-model="form.organization.name"
        label="Name"
        placeholder="Fachschaftsrat Elektrotechnik"
        required
        :error="form.errors['organization.name']"
      />
      <UiInput
        v-model="form.organization.short_name"
        label="Kurzname"
        placeholder="FSR 5"
        required
        :error="form.errors['organization.short_name']"
      />
      <UiInput
        v-model="form.organization.accent_color"
        label="Akzentfarbe"
        type="color"
        :error="form.errors['organization.accent_color']"
      />
    </fieldset>

    <fieldset class="space-y-4">
      <legend class="font-semibold">3. Admin-Konto</legend>
      <div class="grid gap-4 sm:grid-cols-2">
        <UiInput
          v-model="form.admin.firstname"
          label="Vorname"
          required
          :error="form.errors['admin.firstname']"
        />
        <UiInput
          v-model="form.admin.lastname"
          label="Nachname"
          required
          :error="form.errors['admin.lastname']"
        />
      </div>
      <UiInput
        v-model="form.admin.email"
        label="E-Mail-Adresse"
        type="email"
        autocomplete="username"
        required
        :error="form.errors['admin.email']"
      />
      <UiInput
        v-model="form.admin.password"
        label="Passwort"
        type="password"
        autocomplete="new-password"
        hint="Mindestens 12 Zeichen."
        required
        :error="form.errors['admin.password']"
      />
      <UiInput
        v-model="form.admin.password_confirmation"
        label="Passwort wiederholen"
        type="password"
        autocomplete="new-password"
        required
      />
    </fieldset>

    <fieldset class="space-y-4">
      <legend class="font-semibold">4. Rechtliches</legend>
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
    </fieldset>

    <fieldset class="space-y-4">
      <legend class="font-semibold">5. E-Mail</legend>
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
    </fieldset>

    <fieldset class="space-y-4">
      <legend class="font-semibold">6. Rollen und Module</legend>
      <p class="text-sm text-ink-muted">
        Namen der Startrollen. Die Rolle „Admin“ gibt es immer.
      </p>
      <UiInput
        v-for="role in startRoles"
        :key="role"
        v-model="form.roles[role]"
        :label="role"
      />
      <div v-if="modules.length" class="space-y-3">
        <UiCheckbox
          v-for="module in modules"
          :key="module.key"
          v-model="form.modules"
          :value="module.key"
          :label="module.name"
          :description="module.description"
        />
      </div>
    </fieldset>

    <UiButton type="submit" class="w-full" :disabled="form.processing">
      Einrichten
    </UiButton>
  </form>
</template>

<script setup lang="ts">
import { Head, useForm } from "@inertiajs/vue3";

/**
 * Setup wizard of a new instance, see app/Http/Controllers/SetupController.php.
 */
const props = defineProps<{
  hasToken: boolean;
  startRoles: string[];
  modules: { key: string; name: string; description: string }[];
  defaults: Record<string, string | null>;
}>();

const form = useForm({
  token: "",
  organization: {
    name: "",
    short_name: "",
    accent_color: props.defaults["organization.accent_color"] ?? "#22948c",
  },
  admin: {
    firstname: "",
    lastname: "",
    email: "",
    password: "",
    password_confirmation: "",
  },
  legal: { imprint_url: "", privacy_url: "", contact_email: "" },
  mail: { from_name: "", from_address: "" },
  roles: Object.fromEntries(props.startRoles.map((role) => [role, role])),
  modules: [] as string[],
});

const submit = () =>
  form
    .transform((data) => ({
      ...data,
      legal: Object.fromEntries(
        Object.entries(data.legal).map(([key, value]) => [key, value || null]),
      ),
      mail: Object.fromEntries(
        Object.entries(data.mail).map(([key, value]) => [key, value || null]),
      ),
    }))
    .post("/setup");
</script>
