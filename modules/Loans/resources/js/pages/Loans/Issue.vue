<template>
  <UiPageHeader :title="`Ausgabe ${loan.number}`" :description="loan.title">
    <UiButton variant="ghost" icon="arrow-left" :href="`/loans/${loan.id}`"
      >Zur Buchung</UiButton
    >
  </UiPageHeader>

  <div class="space-y-4">
    <UiAlert v-if="errorMessages.length" type="error">{{
      errorMessages.join(" ")
    }}</UiAlert>
    <!-- a scanned item that is not in the loan -->
    <UiAlert v-if="foreign" :type="foreign.free ? 'warning' : 'error'">
      {{ foreign.message }}
      <button
        v-if="foreign.free && can('loans.manage')"
        type="button"
        class="ml-1 font-bold underline"
        @click="addForeign"
      >
        Zur Buchung hinzufügen
      </button>
    </UiAlert>
  </div>

  <div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
      <UiCard
        title="1. Scannen"
        :description="`${checkedCount} von ${positionCount} Positionen abgehakt. Jeder Scan hakt eine Position ab; der Inhalt einer Box kann einzeln abgehakt werden, muss aber nicht.`"
      >
        <LoansScanInput @scan="scan" />
      </UiCard>

      <UiCard flush>
        <ul class="divide-y divide-line">
          <li
            v-for="row in rows"
            :key="row.id"
            :class="[
              'py-3 pr-4',
              row.checked_at
                ? 'bg-green-50 dark:bg-green-950/40'
                : row.depth === 0
                  ? ''
                  : 'text-ink-muted',
            ]"
            :style="{ paddingLeft: `${1 + row.depth}rem` }"
          >
            <div class="flex items-start gap-3">
              <input
                type="checkbox"
                class="mt-0.5 h-6 w-6 shrink-0 rounded border-line text-accent focus:ring-accent"
                :checked="!!row.checked_at"
                :aria-label="`${row.name} abhaken`"
                @change="toggle(row)"
              />
              <div class="min-w-0 flex-1 space-y-1">
                <p
                  :class="['break-words', row.depth === 0 ? 'font-medium' : '']"
                >
                  <FontAwesomeIcon
                    v-if="row.is_container"
                    :icon="['fas', 'box']"
                    class="mr-1"
                  />{{ row.name }}
                </p>
                <p class="text-xs">
                  <span class="whitespace-nowrap font-mono">{{
                    row.number
                  }}</span>
                  <template v-if="row.kind === 'bulk' && row.depth > 0">
                    · {{ row.quantity }} {{ row.unit }}</template
                  >
                  <template v-if="row.checked_at">
                    ·
                    {{
                      row.checked_by_scan ? "gescannt" : "von Hand"
                    }}</template
                  >
                </p>
                <p
                  v-for="note in [...row.box_checks, ...row.hints]"
                  :key="note"
                  class="text-xs text-yellow-700"
                >
                  {{ note }}
                </p>
                <p
                  v-if="row.is_container && !row.hints.length"
                  class="text-xs text-ink-muted"
                >
                  Abhaken hakt den Inhalt mit ab.
                </p>
                <div
                  v-if="
                    (row.kind === 'bulk' && row.depth === 0) ||
                    (row.depth === 0 && !row.checked_at && can('loans.manage'))
                  "
                  class="flex flex-wrap items-center gap-x-3 gap-y-2 pt-1"
                >
                  <!-- bulk items: the scan asks for the quantity -->
                  <label
                    v-if="row.kind === 'bulk' && row.depth === 0"
                    class="flex items-center gap-2 text-sm"
                  >
                    <input
                      v-model="quantities[row.id]"
                      type="number"
                      min="1"
                      :max="row.stock"
                      inputmode="numeric"
                      aria-label="Anzahl"
                      @change="row.checked_at && toggleCount(row)"
                      class="w-20 rounded-md border-0 bg-surface py-1.5 text-ink ring-1 ring-inset ring-line"
                    />
                    {{ row.unit }}
                  </label>
                  <UiButton
                    v-if="
                      row.depth === 0 && !row.checked_at && can('loans.manage')
                    "
                    variant="ghost"
                    size="sm"
                    icon="xmark"
                    @click="removeRow(row.id)"
                  >
                    Aus der Buchung
                  </UiButton>
                </div>
              </div>
            </div>
          </li>
        </ul>
        <p
          v-if="missing.length"
          class="border-t border-line px-4 py-3 text-sm text-red-600 sm:px-6"
        >
          Fehlt noch: {{ missing.map((row) => row.number).join(", ") }}. Von
          Hand abhaken oder aus der Buchung nehmen.
        </p>
      </UiCard>
    </div>

    <div class="space-y-6">
      <UiCard v-if="!loan.internal" title="2. Verantwortliche Person">
        <p v-if="!changingResponsible" class="text-sm">
          <strong>{{ loan.responsible?.name }}</strong
          ><br />
          {{ loan.responsible?.email }} · {{ loan.responsible?.phone }}<br />
          {{ loan.responsible?.address }}
        </p>
        <p
          v-if="loan.responsible?.missing?.length"
          class="text-sm text-red-600"
        >
          Es fehlen: {{ loan.responsible.missing.join(", ") }}
        </p>
        <LoansContactPicker
          v-if="changingResponsible"
          v-model="newResponsible"
          label="Wer holt ab?"
          :councils="councils"
        />
        <UiButton
          v-if="!changingResponsible"
          variant="secondary"
          size="sm"
          class="mt-3"
          @click="changingResponsible = true"
        >
          Jemand anderes holt ab
        </UiButton>
        <UiButton
          v-else-if="newResponsible"
          size="sm"
          class="mt-3"
          @click="saveResponsible"
          >Übernehmen</UiButton
        >
      </UiCard>

      <UiCard :title="loan.internal ? '2. Dokumente' : '3. Dokumente'">
        <div class="flex flex-wrap gap-2">
          <UiButton
            v-if="!loan.internal"
            variant="secondary"
            size="sm"
            icon="file-pdf"
            :href="`/loans/${loan.id}/preview/contract.pdf`"
            external
            >Leihvertrag</UiButton
          >
          <UiButton
            variant="secondary"
            size="sm"
            icon="file-pdf"
            :href="`/loans/${loan.id}/preview/list.pdf`"
            external
            >Materialliste</UiButton
          >
        </div>
        <details v-if="contract" class="mt-3 text-sm">
          <summary class="cursor-pointer font-medium">
            Vertrag hier lesen
          </summary>
          <!-- html from the server: escaped text with headings and lists (ContractTemplate::render) -->
          <div
            class="contract mt-2 max-h-96 space-y-2 overflow-y-auto rounded bg-surface-muted p-3"
            v-html="contract"
          />
        </details>
      </UiCard>

      <UiCard :title="loan.internal ? '3. Abschluss' : '4. Unterschriften'">
        <form class="space-y-5" @submit.prevent="complete">
          <template v-if="!loan.internal">
            <UiCheckbox
              v-model="form.on_paper"
              label="Auf Papier unterschrieben"
              description="Beide PDFs drucken, mit Stift unterschreiben; ein Foto oder Scan lässt sich anhängen."
            />
            <template v-if="!form.on_paper">
              <LoansSignaturePad
                v-model="form.signatures.responsible"
                label="Verantwortliche Person"
                :name="loan.responsible?.name ?? ''"
                :error="form.errors['signatures.responsible']"
              />
              <LoansSignaturePad
                v-model="form.signatures.issuer"
                label="Für den Verleiher"
                :name="issuer"
                :error="form.errors['signatures.issuer']"
              />
            </template>
            <label v-else class="block text-sm">
              <span class="font-medium"
                >Foto oder Scan des Blatts (optional)</span
              >
              <input
                type="file"
                accept="image/jpeg,image/png,image/webp,application/pdf"
                class="mt-1 block w-full text-sm"
                @change="
                  form.scan =
                    ($event.target as HTMLInputElement).files?.[0] ?? null
                "
              />
            </label>
          </template>
          <p v-else class="text-sm text-ink-muted">
            Eigenbedarf: ohne Vertrag und Unterschrift, nur mit der
            Materialliste.
          </p>
          <UiButton
            type="submit"
            icon="right-from-bracket"
            class="w-full"
            :disabled="form.processing || missing.length > 0"
          >
            Ausgeben
          </UiButton>
        </form>
      </UiCard>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { router, useForm, usePage } from "@inertiajs/vue3";
import type { Contact, LoanSummary } from "../../http";
import LoansScanInput from "../../components/ScanInput.vue";
import LoansSignaturePad from "../../components/SignaturePad.vue";
import LoansContactPicker from "../../components/ContactPicker.vue";

type Row = {
  id: number;
  parent_id: number | null;
  depth: number;
  number: string;
  name: string;
  kind: "single" | "bulk";
  unit: string | null;
  is_container: boolean;
  quantity: number;
  stock: number;
  checked_at: string | null;
  checked_by_scan: boolean;
  box_checks: string[];
  hints: string[];
};

/**
 * Issue of a confirmed loan: scan and tick off, responsible person, documents, signatures. The
 * ticks are stored on the server at once, so the issue can go on on another device.
 */
const props = defineProps<{
  loan: LoanSummary & {
    internal: boolean;
    responsible: Contact | null;
    borrower: string | null;
  };
  rows: Row[];
  contract: string | null;
  issuer: string;
  councils: Option[];
  foreign: {
    id: number;
    number: string;
    name: string;
    kind: string;
    free: boolean;
    message: string;
  } | null;
}>();

const page = usePage<SharedProps>();
const { can } = usePermissions();
const errorMessages = computed(() =>
  ["loan", "code", "quantity", "item", "responsible_contact_id"]
    .map((key) => page.props.errors?.[key])
    .filter(Boolean),
);

const positions = computed(() => props.rows.filter((row) => row.depth === 0));
const positionCount = computed(() => positions.value.length);
const checkedCount = computed(
  () => positions.value.filter((row) => row.checked_at).length,
);
const missing = computed(() =>
  positions.value.filter((row) => !row.checked_at),
);

const quantities = reactive<Record<number, number>>(
  Object.fromEntries(props.rows.map((row) => [row.id, row.quantity])),
);
// after every answer the fields show what was stored
watch(
  () => props.rows,
  (rows) => rows.forEach((row) => (quantities[row.id] = row.quantity)),
);
const options = { preserveScroll: true, preserveState: true };

const scan = (code: string) => {
  const row = props.rows.find(
    (entry) =>
      entry.depth === 0 &&
      entry.number.replace(/-/g, "") === code.toUpperCase().replace(/-/g, ""),
  );
  router.post(
    `/loans/${props.loan.id}/issue/check`,
    { code, quantity: row?.kind === "bulk" ? quantities[row.id] : null },
    options,
  );
};
const toggle = (row: Row) =>
  row.checked_at
    ? router.post(
        `/loans/${props.loan.id}/issue/rows/${row.id}/uncheck`,
        {},
        options,
      )
    : router.post(
        `/loans/${props.loan.id}/issue/check`,
        {
          row_id: row.id,
          quantity:
            row.kind === "bulk" && row.depth === 0 ? quantities[row.id] : null,
        },
        options,
      );
// a changed count on a ticked row is sent again, the row stays ticked
const toggleCount = (row: Row) =>
  router.post(
    `/loans/${props.loan.id}/issue/check`,
    { row_id: row.id, quantity: quantities[row.id] },
    options,
  );
const removeRow = (id: number) =>
  router.delete(`/loans/${props.loan.id}/positions/${id}`, options);
const addForeign = () => {
  if (props.foreign) {
    router.post(
      `/loans/${props.loan.id}/positions`,
      { item_id: props.foreign.id },
      {
        preserveScroll: true,
        onSuccess: () => router.visit(`/loans/${props.loan.id}/issue`),
      },
    );
  }
};

const changingResponsible = ref(false);
const newResponsible = ref<Contact | null>(null);
const saveResponsible = () =>
  router.post(
    `/loans/${props.loan.id}/issue/responsible`,
    { responsible_contact_id: newResponsible.value?.id },
    { ...options, onSuccess: () => (changingResponsible.value = false) },
  );

const form = useForm({
  on_paper: false,
  signatures: {
    responsible: null as string | null,
    issuer: null as string | null,
  },
  scan: null as File | null,
});
const complete = () =>
  form.post(`/loans/${props.loan.id}/issue/complete`, {
    forceFormData: form.scan !== null,
  });
</script>

<style scoped>
.contract :deep(.nowrap) {
  white-space: nowrap;
}
.contract :deep(h2) {
  font-weight: 700;
}
.contract :deep(h3) {
  font-weight: 600;
  margin-top: 0.5rem;
}
.contract :deep(ol) {
  list-style: decimal;
  padding-left: 1.25rem;
}
.contract :deep(ul) {
  list-style: disc;
  padding-left: 1.25rem;
}
</style>
