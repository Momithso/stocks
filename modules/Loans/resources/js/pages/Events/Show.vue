<template>
  <UiPageHeader
    :title="event.name"
    :description="`${formatDateTime(event.starts_at)} bis ${formatDateTime(event.ends_at)}${event.place ? ' · ' + event.place : ''}`"
  >
    <UiButton
      v-if="can('loans.manage')"
      icon="plus"
      :href="`/loans/create?event=${event.id}`"
      >Buchung dazu</UiButton
    >
    <UiButton
      v-if="can('loans.manage')"
      variant="secondary"
      icon="pen"
      @click="editing = true"
      >Bearbeiten</UiButton
    >
    <UiButton
      v-if="can('loans.manage') && !loans.length"
      variant="ghost"
      icon="trash"
      @click="router.delete(`/loans/events/${event.id}`)"
      >Löschen</UiButton
    >
  </UiPageHeader>

  <div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
      <UiCard title="Buchungen" flush>
        <ul v-if="loans.length" class="divide-y divide-line">
          <li
            v-for="loan in loans"
            :key="loan.id"
            class="flex flex-wrap items-center justify-between gap-2 px-4 py-2 sm:px-6"
          >
            <span>
              <UiLink :href="`/loans/${loan.id}`" class="font-medium">{{
                loan.title
              }}</UiLink>
              <span class="block text-xs text-ink-muted"
                >{{ formatDateTime(loan.pickup_at) }} bis
                {{ formatDateTime(loan.return_at) }} ·
                {{ loan.responsible }}</span
              >
            </span>
            <LoansStatusBadge :loan="loan" />
          </li>
        </ul>
        <UiEmptyState
          v-else
          icon="calendar-check"
          title="Noch keine Buchungen"
        />
      </UiCard>
      <UiCard title="Gemeinsame Materialliste" flush>
        <table class="min-w-full divide-y divide-line text-sm">
          <tbody class="divide-y divide-line">
            <tr v-for="(row, index) in materials" :key="index">
              <td
                class="py-2 pr-2 font-mono text-xs"
                :style="{ paddingLeft: `${1 + row.depth * 1.25}rem` }"
              >
                {{ row.number }}
              </td>
              <td :class="['py-2', row.depth === 0 ? 'font-medium' : '']">
                {{ row.name }}
              </td>
              <td class="py-2">
                {{ row.bulk ? `${row.quantity} ${row.unit}` : "" }}
              </td>
              <td class="py-2 pr-4 font-mono text-xs text-ink-muted">
                {{ row.loan }}
              </td>
            </tr>
          </tbody>
        </table>
      </UiCard>
    </div>
    <UiCard title="Daten" class="order-first lg:order-none">
      <dl class="space-y-3 text-sm">
        <div>
          <dt class="text-ink-muted">Verantwortlich</dt>
          <dd>{{ event.responsible?.name ?? "–" }}</dd>
        </div>
        <div v-if="event.notes">
          <dt class="text-ink-muted">Notiz</dt>
          <dd class="whitespace-pre-line">{{ event.notes }}</dd>
        </div>
      </dl>
    </UiCard>
  </div>

  <LoansEventForm
    v-if="editing"
    :event="event"
    :councils="councils"
    @close="editing = false"
  />
</template>

<script setup lang="ts">
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import { formatDateTime, type Contact, type LoanSummary } from "../../http";
import LoansStatusBadge from "../../components/StatusBadge.vue";
import LoansEventForm, { type EventData } from "../../components/EventForm.vue";

/**
 * An event with its loans and their common material list.
 */
defineProps<{
  event: EventData & { responsible: Contact | null };
  loans: LoanSummary[];
  materials: {
    number: string;
    name: string;
    quantity: number;
    unit: string | null;
    bulk: boolean;
    depth: number;
    loan: string;
  }[];
  councils: Option[];
}>();

const { can } = usePermissions();
const editing = ref(false);
</script>
