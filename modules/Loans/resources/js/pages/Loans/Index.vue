<template>
  <UiPageHeader
    title="Buchungen"
    :description="contact ? `Buchungen von ${contact.name}` : undefined"
  >
    <UiButton variant="secondary" icon="calendar-days" href="/loans/calendar"
      >Kalender</UiButton
    >
    <UiButton v-if="can('loans.manage')" icon="plus" href="/loans/create"
      >Neue Buchung</UiButton
    >
  </UiPageHeader>

  <UiCard flush>
    <div
      class="grid gap-4 border-b border-line p-4 sm:grid-cols-2 sm:px-6 lg:grid-cols-4"
    >
      <UiSelect
        v-model="filter.status"
        label="Status"
        :options="statuses"
        @update:model-value="apply"
      />
      <UiSelect
        v-model="filter.event"
        label="Veranstaltung"
        :options="events"
        @update:model-value="apply"
      />
      <UiInput
        v-model="filter.from"
        label="Von"
        type="date"
        @update:model-value="apply"
      />
      <UiInput
        v-model="filter.to"
        label="Bis"
        type="date"
        @update:model-value="apply"
      />
    </div>
    <UiTable
      ref="table"
      data="loans"
      :columns="columns"
      searchable
      search-placeholder="Nummer, Anlass, Kontakt oder Veranstaltung"
      stacked
    >
      <template #cell-number="{ row }">
        <UiLink :href="`/loans/${row.id}`" class="font-mono font-medium">{{
          row.number
        }}</UiLink>
        <p class="text-sm text-ink-muted">{{ row.purpose ?? row.event }}</p>
      </template>
      <template #cell-status="{ row }">
        <LoansStatusBadge :loan="row" />
      </template>
      <template #cell-pickup="{ row }">
        {{ formatDateTime(row.pickup_at) }}
      </template>
      <template #cell-return="{ row }">
        {{ formatDateTime(row.return_at) }}
      </template>
    </UiTable>
  </UiCard>
</template>

<script setup lang="ts">
import { ref } from "vue";
import { formatDateTime } from "../../http";
import LoansStatusBadge from "../../components/StatusBadge.vue";

/**
 * List of loans with filters by status, event and time.
 */
const props = defineProps<{
  statuses: Option[];
  events: Option[];
  filters: {
    status?: string;
    from?: string;
    to?: string;
    contact?: string;
    event?: string;
  };
  contact: { id: number; name: string } | null;
}>();

const { can } = usePermissions();
const table = ref<{
  reload: (changes: Record<string, string | null>) => void;
} | null>(null);

const columns: TableColumn[] = [
  { name: "number", header: "Buchung", sortKey: "number" },
  {
    name: "responsible",
    header: "Verantwortlich",
    value: (row) => row.responsible,
  },
  { name: "pickup", header: "Abholung", sortKey: "pickup" },
  { name: "return", header: "Rückgabe", sortKey: "return" },
  { name: "status", header: "Status", sortKey: "status" },
];

const filter = ref({
  status: props.filters.status ?? null,
  event: props.filters.event ? Number(props.filters.event) : null,
  from: props.filters.from ?? "",
  to: props.filters.to ?? "",
});

const apply = () =>
  table.value?.reload({
    status: filter.value.status ? String(filter.value.status) : null,
    event: filter.value.event ? String(filter.value.event) : null,
    from: filter.value.from || null,
    to: filter.value.to || null,
    contact: props.filters.contact ?? null,
  });
</script>
