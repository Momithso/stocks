<template>
  <UiModal
    :title="data?.loan.title ?? 'Buchung'"
    size="lg"
    @close="emit('close')"
  >
    <p v-if="!data" class="text-sm text-ink-muted">Wird geladen …</p>
    <div v-else class="space-y-5">
      <div class="space-y-1 text-sm">
        <LoansStatusBadge :loan="data.loan" />
        <p>
          <span class="text-ink-muted">Verantwortlich:</span>
          {{ data.loan.responsible ?? "–" }}
        </p>
        <p>
          <span class="text-ink-muted">Zeitraum:</span>
          {{ formatDateTime(data.loan.pickup_at) }} bis
          {{ formatDateTime(data.loan.return_at) }}
        </p>
        <p v-if="data.loan.returned_at">
          <span class="text-ink-muted">Zurück:</span>
          {{ formatDateTime(data.loan.returned_at) }}
        </p>
      </div>

      <!-- every position; defects stand out -->
      <ul
        class="max-h-80 divide-y divide-line overflow-y-auto rounded-md ring-1 ring-line"
      >
        <li
          v-for="row in data.rows"
          :key="row.id"
          :class="[
            'py-2 pr-3 text-sm',
            row.state && row.state !== 'ok'
              ? 'bg-red-50 dark:bg-red-950/40'
              : '',
          ]"
          :style="{ paddingLeft: `${0.75 + row.depth}rem` }"
        >
          <p :class="row.depth === 0 ? 'font-medium' : ''">
            {{ row.name }}
            <span class="font-mono text-xs text-ink-muted">{{
              row.number
            }}</span>
          </p>
          <p v-if="row.state && row.state !== 'ok'" class="text-xs">
            <strong>{{ row.state_label }}</strong
            ><template v-if="row.note"> – {{ row.note }}</template>
          </p>
          <p v-else-if="!row.issued" class="text-xs text-ink-muted">
            Rest, noch nicht ausgegeben
          </p>
          <p v-else-if="!row.returned" class="text-xs text-ink-muted">
            noch ausgegeben
          </p>
          <p v-if="row.issue_defect" class="text-xs text-yellow-700">
            Schon bei der Ausgabe: {{ row.issue_defect }}
          </p>
        </li>
      </ul>

      <!-- still missing: turns up again -->
      <div v-if="data.missing.length" class="space-y-2">
        <h3 class="text-sm font-semibold">Noch vermisst</h3>
        <div
          v-for="row in data.missing"
          :key="row.id"
          class="flex items-center justify-between gap-3 text-sm"
        >
          <span
            >{{ row.name }}
            <span class="font-mono text-xs text-ink-muted">{{
              row.number
            }}</span></span
          >
          <UiButton
            v-if="data.can_found"
            size="sm"
            variant="secondary"
            icon="rotate-left"
            @click="found(row.id)"
          >
            Wieder da
          </UiButton>
        </div>
      </div>

      <!-- closing the defects writes off what is still missing -->
      <form
        v-if="data.can_close_defects"
        class="space-y-3 border-t border-line pt-4"
        @submit.prevent="closeDefects"
      >
        <UiAlert v-if="data.missing.length" type="warning">
          Was noch vermisst ist, wird als verloren ausgemustert.
        </UiAlert>
        <UiTextarea
          v-model="defectsForm.note"
          label="Notiz zum Abschluss der Mängel"
          required
          :error="defectsForm.errors.note"
        />
        <UiButton
          type="submit"
          icon="check"
          class="w-full"
          :disabled="defectsForm.processing"
        >
          Mängel abschließen
        </UiButton>
      </form>

      <div class="flex flex-wrap gap-2">
        <UiButton
          v-if="data.issue_href"
          icon="right-from-bracket"
          :href="data.issue_href"
        >
          Rest ausgeben
        </UiButton>
        <UiButton
          v-if="data.return_href"
          icon="rotate-left"
          :href="data.return_href"
        >
          Rückgabe
        </UiButton>
        <UiButton
          v-if="data.inspect_href"
          icon="clipboard-check"
          :href="data.inspect_href"
        >
          Prüfen
        </UiButton>
        <UiButton
          v-if="data.href"
          variant="secondary"
          icon="arrow-up-right-from-square"
          :href="data.href"
        >
          Zur Buchung
        </UiButton>
      </div>
    </div>
  </UiModal>
</template>

<script setup lang="ts">
import { onMounted, ref } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import { formatDateTime, getJson, type LoanSummary } from "../http";
import LoansStatusBadge from "./StatusBadge.vue";

/**
 * A loan in short, for surfaces without the loan page (kiosk): positions with their defects,
 * "Wieder da" for what is still missing, closing the defects, and the next step.
 */
const props = defineProps<{ loanId: number }>();
const emit = defineEmits<{ close: [] }>();

const { url } = useSurface();

type Details = {
  loan: LoanSummary & { returned_at: string | null; open_defects: boolean };
  rows: {
    id: number;
    depth: number;
    number: string;
    name: string;
    state: string | null;
    state_label: string | null;
    note: string | null;
    returned: boolean;
    issued: boolean;
    issue_defect: string | null;
  }[];
  missing: { id: number; number: string; name: string }[];
  can_found: boolean;
  can_close_defects: boolean;
  return_href: string | null;
  issue_href: string | null;
  inspect_href: string | null;
  href: string | null;
};

const data = ref<Details | null>(null);
const load = async () => {
  data.value = await getJson<Details>(url(`/loans/${props.loanId}/details`));
};
onMounted(load);

const options = { preserveScroll: true, onSuccess: load };
const found = (rowId: number) =>
  router.post(
    url(`/loans/${props.loanId}/missing/${rowId}/found`),
    {},
    options,
  );

const defectsForm = useForm({ note: "" });
const closeDefects = () =>
  defectsForm.post(url(`/loans/${props.loanId}/close-defects`), {
    preserveScroll: true,
    onSuccess: () => emit("close"),
  });
</script>
