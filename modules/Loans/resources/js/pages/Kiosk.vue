<template>
  <UiPageHeader
    :title="title"
    description="Scanne die Buchungsnummer, eine Box oder einen Gegenstand der Buchung."
  >
    <UiButton
      v-if="unchecked"
      variant="secondary"
      icon="clipboard-check"
      :href="url('/loans/returns')"
    >
      {{ unchecked }} ungeprüft
    </UiButton>
  </UiPageHeader>

  <UiCard class="mb-6">
    <LoansScanInput placeholder="B-2026-0042 oder Code scannen" @scan="find" />
  </UiCard>

  <div class="grid gap-6 lg:grid-cols-2">
    <UiCard v-if="show !== 'issued'" title="Heute abholen" flush>
      <ul v-if="pickups.length" class="divide-y divide-line">
        <li v-for="loan in pickups" :key="loan.id">
          <InertiaLink
            :href="url(`/loans/${loan.id}/issue`)"
            class="flex items-center justify-between gap-3 px-4 py-4 hover:bg-surface-muted sm:px-6"
          >
            <span class="min-w-0">
              <span class="block font-medium">{{ loan.title }}</span>
              <span class="block text-sm text-ink-muted">
                {{ loan.responsible }} · {{ formatDateTime(loan.pickup_at) }}
              </span>
              <span v-if="loan.rest" class="mt-1 inline-block">
                <UiBadge variant="accent">Rest nachausgeben</UiBadge>
              </span>
              <span v-else-if="loan.missed" class="mt-1 inline-block">
                <UiBadge variant="warning">Abholung verpasst</UiBadge>
              </span>
            </span>
            <FontAwesomeIcon :icon="['fas', 'chevron-right']" />
          </InertiaLink>
        </li>
      </ul>
      <UiEmptyState
        v-else
        icon="calendar-check"
        title="Heute wird nichts abgeholt"
      />
    </UiCard>

    <UiCard v-if="show !== 'pickups'" title="Ausgegeben" flush>
      <ul v-if="issued.length" class="divide-y divide-line">
        <li v-for="loan in issued" :key="loan.id">
          <InertiaLink
            :href="url(`/loans/${loan.id}/return`)"
            class="flex items-center justify-between gap-3 px-4 py-4 hover:bg-surface-muted sm:px-6"
          >
            <span class="min-w-0">
              <span class="block font-medium">{{ loan.title }}</span>
              <span class="block text-sm text-ink-muted">
                {{ loan.responsible }} · bis
                {{ formatDateTime(loan.return_at) }}
              </span>
              <LoansStatusBadge v-if="loan.overdue_days" :loan="loan" />
            </span>
            <FontAwesomeIcon :icon="['fas', 'chevron-right']" />
          </InertiaLink>
        </li>
      </ul>
      <UiEmptyState v-else icon="rotate-left" title="Nichts ausgegeben" />
    </UiCard>

    <!-- open defects: closed with defects, or a part came back with defects -->
    <UiCard v-if="show !== 'pickups'" title="Mit Mängeln" flush>
      <ul v-if="defects.length" class="divide-y divide-line">
        <li v-for="loan in defects" :key="loan.id">
          <button
            type="button"
            class="flex w-full items-center justify-between gap-3 px-4 py-4 text-left hover:bg-surface-muted sm:px-6"
            @click="loanId = loan.id"
          >
            <span class="min-w-0">
              <span class="block font-medium">{{ loan.title }}</span>
              <span class="block text-sm text-ink-muted">
                {{ loan.responsible }}
              </span>
              <LoansStatusBadge :loan="loan" />
            </span>
            <FontAwesomeIcon :icon="['fas', 'chevron-right']" />
          </button>
        </li>
      </ul>
      <UiEmptyState v-else icon="circle-check" title="Keine offenen Mängel" />
    </UiCard>
  </div>

  <LoansLoanModal v-if="loanId" :loan-id="loanId" @close="loanId = null" />
</template>

<script setup lang="ts">
import { computed, ref } from "vue";
import { Link as InertiaLink, router } from "@inertiajs/vue3";
import LoansScanInput from "../components/ScanInput.vue";
import LoansStatusBadge from "../components/StatusBadge.vue";
import LoansLoanModal from "../components/LoanModal.vue";
import { formatDateTime, type LoanSummary } from "../http";

/**
 * Loan tasks on the kiosk: pick a loan of the lists or scan, then issue, return or inspect it on
 * the pages of the pc.
 */
const props = defineProps<{
  pickups: (LoanSummary & { rest: boolean; missed: boolean })[];
  issued: LoanSummary[];
  unchecked: number;
  defects: LoanSummary[];
  show: "pickups" | "issued" | null;
}>();

const { url } = useSurface();
const loanId = ref<number | null>(null);

const title = computed(() =>
  props.show === "pickups"
    ? "Ausgabe"
    : props.show === "issued"
      ? "Rückgabe"
      : "Verleih",
);

// the server opens the right step for the code
const find = (code: string) =>
  router.get(url("/loans/find"), { code }, { preserveScroll: true });
</script>
