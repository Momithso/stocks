<template>
  <UiPageHeader
    title="Rückgaben"
    description="Was zurück ist, aber noch geprüft oder eingeräumt werden muss."
  />

  <UiAlert v-if="errorMessages.length" type="error" class="mb-4">{{
    errorMessages.join(" ")
  }}</UiAlert>

  <div class="grid gap-6 lg:grid-cols-2">
    <UiCard
      title="Noch nicht eingeräumt"
      :description="`${pending.length} Gegenstände. Gegenstand scannen, dann das Regal.`"
    >
      <div class="space-y-4">
        <UiAlert v-if="current" type="info">
          <strong>{{ current.number }} {{ current.name }}</strong>
          <template v-if="current.merge_to">
            geht zurück zu {{ current.merge_to }}.</template
          >
          <template v-else>
            : Stammplatz <strong>{{ current.home?.code ?? "keiner" }}</strong
            >. Scanne jetzt das Regal.
          </template>
          <button
            type="button"
            class="ml-1 font-bold underline"
            @click="selected = null"
          >
            Abbrechen
          </button>
        </UiAlert>
        <UiButton
          v-if="current?.merge_to"
          icon="rotate"
          class="w-full"
          @click="stow(null)"
        >
          Zurückbuchen zu {{ current.merge_to }}
        </UiButton>
        <LoansScanInput
          :placeholder="current ? 'Regal scannen' : 'Gegenstand scannen'"
          @scan="scanned"
        />
        <ul v-if="pending.length" class="divide-y divide-line">
          <li
            v-for="row in pending"
            :key="row.id"
            :class="[
              'flex items-center justify-between gap-3 py-2',
              row.id === selected ? 'text-accent' : '',
            ]"
          >
            <!-- the item: a window on the kiosk, its page on the pc -->
            <component
              :is="kiosk ? 'button' : InertiaLink"
              :type="kiosk ? 'button' : undefined"
              :href="kiosk ? undefined : `/inventory/items/${row.item_id}`"
              class="min-w-0 flex-1 text-left hover:underline"
              @click="kiosk && (infoRow = row)"
            >
              <span class="font-medium">{{ row.name }}</span>
              <span class="block text-xs text-ink-muted">
                <span class="font-mono">{{ row.number }}</span>
                <template v-if="row.quantity !== null">
                  · {{ row.quantity }} {{ row.unit }}</template
                >
                · aus {{ row.loan.number }} ·
                {{
                  row.merge_to
                    ? `zurück zu ${row.merge_to}`
                    : `Stammplatz ${row.home?.code ?? "–"}`
                }}
              </span>
            </component>
            <UiButton
              size="sm"
              :variant="row.id === selected ? 'primary' : 'secondary'"
              icon="warehouse"
              @click="selected = row.id"
            >
              Einräumen
            </UiButton>
          </li>
        </ul>
        <UiEmptyState v-else icon="warehouse" title="Alles eingeräumt" />
      </div>
    </UiCard>

    <div class="space-y-6">
      <UiCard
        title="Prüfung offen"
        description="Ungeprüft angenommen; die Prüfung schließt die Buchung ab."
        flush
      >
        <ul v-if="unchecked.length" class="divide-y divide-line">
          <li
            v-for="loan in unchecked"
            :key="loan.id"
            class="flex items-center justify-between gap-2 px-4 py-2 sm:px-6"
          >
            <span>
              <button
                v-if="kiosk"
                type="button"
                class="font-medium hover:underline"
                @click="loanId = loan.id"
              >
                {{ loan.title }}
              </button>
              <UiLink v-else :href="`/loans/${loan.id}`" class="font-medium">{{
                loan.title
              }}</UiLink>
              <span class="block text-xs text-ink-muted"
                >zurück <UiDateTime :value="loan.returned_at"
              /></span>
            </span>
            <UiButton
              size="sm"
              icon="clipboard-check"
              :href="url(`/loans/${loan.id}/inspect`)"
              >Prüfen</UiButton
            >
          </li>
        </ul>
        <UiEmptyState
          v-else
          icon="clipboard-check"
          title="Keine Prüfung offen"
        />
      </UiCard>

      <UiCard title="Mängel offen" flush>
        <ul v-if="defects.length" class="divide-y divide-line">
          <li v-for="loan in defects" :key="loan.id" class="px-4 py-2 sm:px-6">
            <button
              v-if="kiosk"
              type="button"
              class="block text-left font-medium hover:underline"
              @click="loanId = loan.id"
            >
              {{ loan.title }}
            </button>
            <UiLink v-else :href="`/loans/${loan.id}`" class="font-medium">{{
              loan.title
            }}</UiLink>
            <span class="block text-xs text-ink-muted">{{
              loan.responsible
            }}</span>
          </li>
        </ul>
        <UiEmptyState v-else icon="circle-check" title="Keine offenen Mängel" />
      </UiCard>
    </div>
  </div>

  <InventoryItemInfoModal
    v-if="infoRow"
    :code="infoRow.number"
    @close="infoRow = null"
  >
    <p>
      <span class="text-ink-muted">Zurück aus:</span>
      {{ infoRow.loan.number }}
    </p>
    <p>
      <span class="text-ink-muted">Gehört nach:</span>
      {{ infoRow.merge_to ?? infoRow.home?.code ?? "kein Stammplatz" }}
    </p>
  </InventoryItemInfoModal>
  <LoansLoanModal v-if="loanId" :loan-id="loanId" @close="loanId = null" />
</template>

<script setup lang="ts">
import { computed, ref } from "vue";
import { Link as InertiaLink, router, usePage } from "@inertiajs/vue3";
import type { LoanSummary } from "../http";
import LoansScanInput from "../components/ScanInput.vue";
import LoansLoanModal from "../components/LoanModal.vue";

const { kiosk, url } = useSurface();

type PendingRow = {
  id: number;
  item_id: number;
  number: string;
  name: string;
  quantity: number | null;
  unit: string | null;
  loan: { id: number; number: string };
  merge_to: string | null;
  home: Place | null;
};

/**
 * Returns: putting back by scan (item, then shelf) or with "Einräumen", open inspections and open
 * defects. On the kiosk, items and loans open a window instead of their page.
 */
const props = defineProps<{
  pending: PendingRow[];
  unchecked: (LoanSummary & { returned_at: string | null })[];
  defects: LoanSummary[];
  selected: number | null;
}>();

const page = usePage<SharedProps>();
const errorMessages = computed(() =>
  ["code", "item", "quantity"]
    .map((key) => page.props.errors?.[key])
    .filter(Boolean),
);
const selected = ref<number | null>(props.selected);
// windows on the kiosk: an item of the list, a loan
const infoRow = ref<PendingRow | null>(null);
const loanId = ref<number | null>(null);
const current = computed(
  () => props.pending.find((row) => row.id === selected.value) ?? null,
);

const normalize = (code: string) =>
  code
    .replace(/^.*\/s\//, "")
    .toUpperCase()
    .replace(/-/g, "");

// first scan: the item; second scan: the place
const scanned = (code: string) => {
  const row = props.pending.find(
    (entry) => normalize(entry.number) === normalize(code),
  );
  if (row) {
    selected.value = row.id;
    return;
  }
  if (current.value) {
    stow(code);
  }
};

const stow = (code: string | null) =>
  router.post(
    url("/loans/returns/stow"),
    { row_id: selected.value, code },
    {
      preserveScroll: true,
      onSuccess: () => (selected.value = null),
    },
  );
</script>
