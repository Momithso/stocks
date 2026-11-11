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
          <li v-for="row in pending" :key="row.id">
            <button
              type="button"
              :class="[
                'flex w-full items-center justify-between gap-2 py-2 text-left',
                row.id === selected ? 'text-accent' : '',
              ]"
              @click="selected = row.id"
            >
              <span class="min-w-0">
                <span class="font-medium">{{ row.name }}</span>
                <span class="block text-xs text-ink-muted">
                  <span class="font-mono">{{ row.number }}</span>
                  <template v-if="row.quantity !== null">
                    · {{ row.quantity }} {{ row.unit }}</template
                  >
                  · aus {{ row.loan.number }}
                </span>
              </span>
              <span class="shrink-0 font-mono text-sm">{{
                row.merge_to ? `→ ${row.merge_to}` : (row.home?.code ?? "–")
              }}</span>
            </button>
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
              <UiLink :href="`/loans/${loan.id}`" class="font-medium">{{
                loan.title
              }}</UiLink>
              <span class="block text-xs text-ink-muted"
                >zurück <UiDateTime :value="loan.returned_at"
              /></span>
            </span>
            <UiButton
              size="sm"
              icon="clipboard-check"
              :href="`/loans/${loan.id}/inspect`"
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
            <UiLink :href="`/loans/${loan.id}`" class="font-medium">{{
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
</template>

<script setup lang="ts">
import { computed, ref } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import type { LoanSummary } from "../http";
import LoansScanInput from "../components/ScanInput.vue";

type PendingRow = {
  id: number;
  number: string;
  name: string;
  quantity: number | null;
  unit: string | null;
  loan: { id: number; number: string };
  merge_to: string | null;
  home: Place | null;
};

/**
 * Returns: putting back by scan (item, then shelf), open inspections and open defects.
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
    "/loans/returns/stow",
    { row_id: selected.value, code },
    {
      preserveScroll: true,
      onSuccess: () => (selected.value = null),
    },
  );
</script>
