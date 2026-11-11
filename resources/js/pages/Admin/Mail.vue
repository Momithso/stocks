<template>
  <UiPageHeader
    title="E-Mail"
    description="Mailserver und Absender für alle E-Mails von Stocks."
  />

  <div class="grid gap-6 lg:grid-cols-3">
    <form class="space-y-6 lg:col-span-2" @submit.prevent="save">
      <UiCard
        title="Mailserver"
        description="Leer lassen, dann gelten die Umgebungsvariablen (MAIL_*)."
      >
        <div class="grid gap-5 sm:grid-cols-2">
          <UiInput
            v-model="form.host"
            label="Server"
            placeholder="z. B. smtp.example.org"
            :error="form.errors.host"
            class="sm:col-span-2"
          />
          <UiInput
            v-model="form.port"
            label="Port"
            type="number"
            inputmode="numeric"
            placeholder="587"
            :error="form.errors.port"
          />
          <UiSelect
            v-model="form.encryption"
            label="Verschlüsselung"
            :options="encryptions"
            placeholder="automatisch (STARTTLS)"
          />
          <UiInput
            v-model="form.username"
            label="Benutzername"
            autocomplete="off"
            :error="form.errors.username"
          />
          <div>
            <UiInput
              v-model="form.password"
              label="Passwort"
              type="password"
              autocomplete="new-password"
              :hint="
                mail.has_password
                  ? 'Gespeichert. Leer lassen, um es zu behalten.'
                  : 'Noch keins gespeichert.'
              "
              :error="form.errors.password"
            />
            <UiCheckbox
              v-if="mail.has_password"
              v-model="form.remove_password"
              label="Gespeichertes Passwort löschen"
              class="mt-2"
            />
          </div>
        </div>
      </UiCard>

      <UiCard title="Absender">
        <div class="grid gap-5 sm:grid-cols-2">
          <UiInput
            v-model="form.from_name"
            label="Absendername"
            :placeholder="$page.props.organization.name"
            :error="form.errors.from_name"
          />
          <UiInput
            v-model="form.from_address"
            label="Absenderadresse"
            type="email"
            :placeholder="environment.from ?? undefined"
            :error="form.errors.from_address"
          />
        </div>
      </UiCard>

      <UiButton type="submit" :disabled="form.processing">Speichern</UiButton>
    </form>

    <div class="order-first space-y-6 lg:order-none">
      <UiCard title="Status">
        <UiAlert
          :type="
            status.ok === true
              ? 'success'
              : status.ok === false
                ? 'error'
                : 'info'
          "
        >
          {{ status.message }}
        </UiAlert>
        <p class="mt-2 text-xs text-ink-muted">
          Geprüft <UiDateTime :value="status.checked_at" />. Das Menü zeigt den
          Stand unter Einstellungen › E-Mail.
        </p>
        <UiButton
          variant="secondary"
          size="sm"
          icon="rotate"
          class="mt-3"
          @click="
            router.post('/admin/mail/check', {}, { preserveScroll: true })
          "
        >
          Erneut prüfen
        </UiButton>
      </UiCard>

      <UiCard title="Test-Mail">
        <form class="space-y-4" @submit.prevent="sendTest">
          <UiInput
            v-model="testForm.to"
            label="An"
            type="email"
            required
            :error="testForm.errors.to"
          />
          <UiButton
            type="submit"
            icon="envelope"
            class="w-full"
            :disabled="testForm.processing"
            >Test-Mail senden</UiButton
          >
        </form>
      </UiCard>
    </div>
  </div>
</template>

<script setup lang="ts">
import { router, useForm } from "@inertiajs/vue3";

/**
 * Mail server, sender, connection status and test mail. The password is never sent to the browser.
 */
const props = defineProps<{
  mail: {
    host: string | null;
    port: number | null;
    username: string | null;
    encryption: string | null;
    from_name: string | null;
    from_address: string | null;
    has_password: boolean;
  };
  status: { ok: boolean | null; message: string; checked_at: string };
  environment: { mailer: string; from: string | null };
  testAddress: string;
}>();

const encryptions: Option[] = [
  { value: "tls", label: "STARTTLS (meist Port 587)" },
  { value: "ssl", label: "SSL/TLS (meist Port 465)" },
];

const form = useForm({
  host: props.mail.host ?? "",
  port: props.mail.port ?? "",
  username: props.mail.username ?? "",
  password: "",
  remove_password: false,
  encryption: props.mail.encryption,
  from_name: props.mail.from_name ?? "",
  from_address: props.mail.from_address ?? "",
});
const save = () =>
  form.put("/admin/mail", {
    preserveScroll: true,
    onSuccess: () => form.reset("password", "remove_password"),
  });

const testForm = useForm({ to: props.testAddress });
const sendTest = () =>
  testForm.post("/admin/mail/test", { preserveScroll: true });
</script>
