<template>
  <UiModal
    :title="event ? 'Veranstaltung bearbeiten' : 'Veranstaltung anlegen'"
    @close="$emit('close')"
  >
    <form class="space-y-4" @submit.prevent="save">
      <UiInput
        v-model="form.name"
        label="Name"
        required
        :error="form.errors.name"
      />
      <div class="grid gap-4 sm:grid-cols-2">
        <UiInput
          v-model="form.starts_at"
          label="Beginn"
          type="datetime-local"
          required
          :error="form.errors.starts_at"
        />
        <UiInput
          v-model="form.ends_at"
          label="Ende"
          type="datetime-local"
          required
          :error="form.errors.ends_at"
        />
      </div>
      <UiInput v-model="form.place" label="Ort" :error="form.errors.place" />
      <LoansContactPicker
        v-model="responsible"
        label="Verantwortlich"
        :councils="councils"
      />
      <UiTextarea v-model="form.notes" label="Notiz" :rows="2" />
      <UiButton type="submit" class="w-full" :disabled="form.processing"
        >Speichern</UiButton
      >
    </form>
  </UiModal>
</template>

<script setup lang="ts">
import { ref } from "vue";
import { useForm } from "@inertiajs/vue3";
import type { Contact } from "../http";
import LoansContactPicker from "./ContactPicker.vue";

export type EventData = {
  id: number;
  name: string;
  starts_at: string;
  ends_at: string;
  place: string | null;
  notes: string | null;
  responsible_contact_id: number | null;
  responsible_name: string | null;
  loans_count: number;
};

/**
 * Dialog to create or change an event.
 */
const props = defineProps<{
  event?: (EventData & { responsible?: Contact | null }) | null;
  councils: Option[];
}>();
const emit = defineEmits<{ close: [] }>();

const responsible = ref<Contact | null>(props.event?.responsible ?? null);
const form = useForm({
  name: props.event?.name ?? "",
  starts_at: props.event?.starts_at.slice(0, 16) ?? "",
  ends_at: props.event?.ends_at.slice(0, 16) ?? "",
  place: props.event?.place ?? "",
  notes: props.event?.notes ?? "",
  responsible_contact_id: null as number | null,
});

const save = () => {
  const data = form.transform((values) => ({
    ...values,
    responsible_contact_id: responsible.value?.id ?? null,
  }));
  const options = { preserveScroll: true, onSuccess: () => emit("close") };
  return props.event
    ? data.put(`/loans/events/${props.event.id}`, options)
    : data.post("/loans/events", options);
};
</script>
