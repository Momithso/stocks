<template>
  <UiCard title="Verleih" flush>
    <div v-if="data" class="space-y-4 py-2">
      <LoansTimeline
        :rows="[
          {
            id: item.id,
            number: item.number,
            name: item.name,
            loans: data.loans,
          },
        ]"
        :from="new Date(data.from)"
        :days="56"
        :link-items="false"
        :labels="false"
        @open="open"
      />
      <ul v-if="data.loans.length" class="divide-y divide-line">
        <li
          v-for="loan in data.loans"
          :key="loan.id"
          class="flex flex-wrap items-center justify-between gap-2 px-4 py-2 sm:px-6"
        >
          <div>
            <UiLink :href="`/loans/${loan.id}`" class="font-medium">{{
              loan.title
            }}</UiLink>
            <p class="text-sm text-ink-muted">
              {{ formatDateTime(loan.pickup_at) }} bis
              {{ formatDateTime(loan.return_at) }}
              <template v-if="loan.responsible">
                · {{ loan.responsible }}</template
              >
            </p>
          </div>
          <LoansStatusBadge :loan="loan" />
        </li>
      </ul>
      <UiEmptyState v-else icon="calendar-check" title="Noch nie verliehen" />
    </div>
    <div v-else class="m-4 h-24 animate-pulse rounded bg-surface-muted" />
  </UiCard>
</template>

<script setup lang="ts">
import { onMounted, ref } from "vue";
import { router } from "@inertiajs/vue3";
import { formatDateTime, getJson, type LoanSummary } from "../http";
import LoansTimeline from "./Timeline.vue";
import LoansStatusBadge from "./StatusBadge.vue";

/**
 * Tab "Verleih" on the page of an item: its timeline of the coming weeks and all its loans.
 */
const props = defineProps<{
  item: { id: number; number: string; name: string };
}>();

const data = ref<{ from: string; to: string; loans: LoanSummary[] } | null>(
  null,
);
onMounted(async () => {
  data.value = await getJson(`/loans/items/${props.item.id}`);
});

const open = (loan: LoanSummary) => router.visit(`/loans/${loan.id}`);
</script>
