<template>
  <UiModal
    :title="contact?.id ? 'Kontakt bearbeiten' : 'Kontakt anlegen'"
    @close="$emit('close')"
  >
    <form class="space-y-4" @submit.prevent="save">
      <UiInput v-model="form.name" label="Name" required :error="errors.name" />
      <div class="grid gap-4 sm:grid-cols-2">
        <UiInput
          v-model="form.email"
          label="E-Mail"
          type="email"
          :error="errors.email"
        />
        <UiInput
          v-model="form.phone"
          label="Telefon"
          type="tel"
          :error="errors.phone"
        />
      </div>
      <UiInput v-model="form.street" label="Straße" :error="errors.street" />
      <div class="grid grid-cols-3 gap-4">
        <UiInput
          v-model="form.zip"
          label="PLZ"
          inputmode="numeric"
          :error="errors.zip"
        />
        <UiInput
          v-model="form.city"
          label="Ort"
          class="col-span-2"
          :error="errors.city"
        />
      </div>
      <UiSelect
        v-model="form.council_id"
        label="Fachschaft"
        :options="councils"
        placeholder="privat"
      />
      <UiTextarea v-model="form.notes" label="Notiz" :rows="2" />
      <p class="text-sm text-ink-muted">
        Wer eine Buchung verantwortet, braucht Name, E-Mail, Telefon und
        Anschrift.
      </p>
      <UiButton type="submit" class="w-full" :disabled="processing">
        Speichern
      </UiButton>
    </form>
  </UiModal>
</template>

<script setup lang="ts">
import { reactive, ref } from "vue";
import { sendJson, type Contact } from "../http";

/**
 * Dialog to create or change a contact without leaving the page (json).
 */
const props = defineProps<{
  contact?: Contact | null;
  councils: Option[];
  // name typed in the search field, taken over for a new contact
  name?: string;
}>();
const emit = defineEmits<{ close: []; saved: [contact: Contact] }>();

const form = reactive({
  name: props.contact?.name ?? props.name ?? "",
  email: props.contact?.email ?? "",
  phone: props.contact?.phone ?? "",
  street: props.contact?.street ?? "",
  zip: props.contact?.zip ?? "",
  city: props.contact?.city ?? "",
  council_id: (props.contact?.council_id ?? null) as number | null,
  notes: props.contact?.notes ?? "",
});
const errors = ref<Record<string, string>>({});
const processing = ref(false);

const save = async () => {
  processing.value = true;
  const result = await sendJson<Contact>(
    props.contact?.id ? "PUT" : "POST",
    props.contact?.id
      ? `/loans/contacts/${props.contact.id}`
      : "/loans/contacts",
    { ...form },
  );
  processing.value = false;
  if (result.ok) {
    emit("saved", result.data);
  } else {
    errors.value = result.errors;
  }
};
</script>
