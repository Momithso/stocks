<template>
  <UiPageHeader :title="isNew ? 'Neue Buchung' : `${loan.number} bearbeiten`" />

  <form class="max-w-3xl space-y-6" @submit.prevent="save">
    <UiCard>
      <div class="space-y-5">
        <UiSelect
          v-model="form.kind"
          label="Art"
          :options="kinds"
          :placeholder="null"
        />
        <UiSelect
          v-model="form.event_id"
          label="Veranstaltung"
          :options="events"
          placeholder="keine"
          hint="Optional. Mehrere Buchungen einer Veranstaltung haben eine gemeinsame Materialliste."
        />
        <div class="grid gap-4 sm:grid-cols-2">
          <UiInput
            v-model="form.pickup_at"
            label="Abholung"
            type="datetime-local"
            required
            :error="form.errors.pickup_at"
          />
          <UiInput
            v-model="form.return_at"
            label="Rückgabe"
            type="datetime-local"
            required
            :error="form.errors.return_at"
          />
        </div>
        <UiInput
          v-model="form.purpose"
          label="Anlass"
          placeholder="z. B. Erstiparty"
          :error="form.errors.purpose"
        />
      </div>
    </UiCard>

    <UiCard v-if="form.kind === 'external'" title="Personen">
      <div class="space-y-5">
        <LoansContactPicker
          v-model="responsible"
          label="Verantwortliche Person"
          :councils="councils"
          hint="Haftet und ist Ansprechpartner. Zum Bestätigen braucht sie Name, E-Mail, Telefon und Anschrift."
          :error="form.errors.responsible_contact_id"
        />
        <UiCheckbox
          v-model="separateBorrower"
          label="Entleiher ist jemand anderes"
          description="Zum Beispiel eine Fachschaft oder ein Verein; sonst leiht die verantwortliche Person selbst."
        />
        <LoansContactPicker
          v-if="separateBorrower"
          v-model="borrower"
          label="Entleiher"
          :councils="councils"
          :error="form.errors.borrower_contact_id"
        />
      </div>
    </UiCard>
    <UiCard v-else title="Eigenbedarf">
      <UiSelect
        v-model="form.responsible_user_id"
        label="Verantwortliches Mitglied"
        :options="members"
        placeholder="bitte wählen"
        hint="Eigenbedarf läuft ohne Vertrag und ohne Unterschrift, nur mit Scan und Materialliste."
      />
      <p
        v-if="form.errors.responsible_user_id"
        class="mt-1 text-sm text-red-600"
      >
        {{ form.errors.responsible_user_id }}
      </p>
    </UiCard>

    <UiCard>
      <UiTextarea
        v-model="form.notes"
        label="Notiz"
        :error="form.errors.notes"
      />
    </UiCard>

    <div class="flex gap-2">
      <UiButton type="submit" :disabled="form.processing">{{
        isNew ? "Anlegen" : "Speichern"
      }}</UiButton>
      <UiButton variant="ghost" :href="isNew ? '/loans' : `/loans/${loan.id}`"
        >Abbrechen</UiButton
      >
    </div>
  </form>
</template>

<script setup lang="ts">
import { ref } from "vue";
import { useForm } from "@inertiajs/vue3";
import type { Contact } from "../../http";
import LoansContactPicker from "../../components/ContactPicker.vue";

/**
 * Form of a loan. A new loan starts as draft; its positions are added on its page.
 */
const props = defineProps<{
  loan: {
    id?: number;
    number?: string;
    kind: string;
    event_id: number | null;
    pickup_at: string;
    return_at: string;
    purpose?: string | null;
    notes?: string | null;
    responsible_user_id?: number | null;
    responsible: Contact | null;
    borrower: Contact | null;
    item_id: number | null;
  };
  isNew: boolean;
  kinds: Option[];
  events: Option[];
  members: Option[];
  councils: Option[];
}>();

const responsible = ref<Contact | null>(props.loan.responsible);
const borrower = ref<Contact | null>(props.loan.borrower);
const separateBorrower = ref(
  props.loan.borrower !== null &&
    props.loan.borrower.id !== props.loan.responsible?.id,
);

const form = useForm({
  kind: props.loan.kind,
  event_id: props.loan.event_id,
  pickup_at: props.loan.pickup_at,
  return_at: props.loan.return_at,
  purpose: props.loan.purpose ?? "",
  notes: props.loan.notes ?? "",
  responsible_user_id: props.loan.responsible_user_id ?? null,
  responsible_contact_id: null as number | null,
  borrower_contact_id: null as number | null,
  item_id: props.loan.item_id,
});

const save = () => {
  const data = form.transform((values) => ({
    ...values,
    responsible_contact_id: responsible.value?.id ?? null,
    borrower_contact_id: separateBorrower.value
      ? (borrower.value?.id ?? null)
      : (responsible.value?.id ?? null),
  }));
  return props.isNew
    ? data.post("/loans")
    : data.put(`/loans/${props.loan.id}`);
};
</script>
