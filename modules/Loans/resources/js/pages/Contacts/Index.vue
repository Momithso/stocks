<template>
  <UiPageHeader
    title="Kontakte"
    description="Entleiher und verantwortliche Personen. Kontaktdaten sieht nur, wer Buchungen bearbeiten darf."
  >
    <UiButton icon="plus" @click="creating = true">Anlegen</UiButton>
  </UiPageHeader>

  <UiCard flush>
    <div class="border-b border-line p-4 sm:px-6">
      <UiSelect
        v-model="council"
        label="Fachschaft"
        :options="councils"
        class="max-w-xs"
        @update:model-value="apply"
      />
    </div>
    <UiTable
      ref="table"
      data="contacts"
      :columns="columns"
      searchable
      search-placeholder="Name, E-Mail, Telefon oder Ort"
      stacked
    >
      <template #cell-name="{ row }">
        <UiLink :href="`/loans/contacts/${row.id}`" class="font-medium">{{
          row.name
        }}</UiLink>
        <UiBadge v-if="row.missing.length" variant="warning" class="ml-2"
          >unvollständig</UiBadge
        >
      </template>
      <template #cell-email="{ row }">
        <UiContactLink kind="email" :value="row.email" :icon="false" />
      </template>
      <template #cell-phone="{ row }">
        <UiContactLink kind="phone" :value="row.phone" :icon="false" />
      </template>
    </UiTable>
  </UiCard>

  <LoansContactForm
    v-if="creating"
    :councils="councils"
    @close="creating = false"
    @saved="(contact) => router.visit(`/loans/contacts/${contact.id}`)"
  />
</template>

<script setup lang="ts">
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import LoansContactForm from "../../components/ContactForm.vue";

/**
 * List of contacts.
 */
defineProps<{ councils: Option[] }>();

const table = ref<{
  reload: (changes: Record<string, string | null>) => void;
} | null>(null);
const council = ref<number | null>(null);
const creating = ref(false);

const columns: TableColumn[] = [
  { name: "name", header: "Name", sortKey: "name" },
  {
    name: "affiliation",
    header: "Fachschaft",
    value: (row) => row.affiliation,
  },
  { name: "email", header: "E-Mail", value: (row) => row.email },
  { name: "phone", header: "Telefon", value: (row) => row.phone },
  { name: "loans", header: "Buchungen", value: (row) => row.loans_count },
];

const apply = () =>
  table.value?.reload({
    council: council.value ? String(council.value) : null,
  });
</script>
