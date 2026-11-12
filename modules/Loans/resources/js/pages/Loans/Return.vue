<template>
  <UiPageHeader
    :title="`${inspect ? 'Prüfung' : 'Rückgabe'} ${loan.number}`"
    :description="loan.title"
  >
    <UiButton
      variant="ghost"
      icon="arrow-left"
      :href="kiosk ? url('/loans') : `/loans/${loan.id}`"
      >{{ kiosk ? "Zur Liste" : "Zur Buchung" }}</UiButton
    >
  </UiPageHeader>

  <LoansSteps
    :steps="steps.map((entry) => entry.label)"
    :current="step"
    :reachable="reachable"
    @select="step = $event"
  />

  <UiAlert v-if="errorMessages.length" type="error" class="mb-4">{{
    errorMessages.join(" ")
  }}</UiAlert>

  <!-- step: count and tick off what came back, mark defects -->
  <div v-if="current === 'material'" class="space-y-6">
    <UiCard
      :title="inspect ? 'Prüfen' : 'Material zählen'"
      :description="`${handledCount} von ${rows.length} erledigt. ${inspect ? 'Jede Position einzeln abhaken oder einen Mangel vermerken.' : 'Scanne jedes Teil oder hake es von Hand ab; was fehlt oder beschädigt ist, als Mangel vermerken.'}`"
    >
      <LoansScanInput @scan="scan" />
    </UiCard>

    <UiCard flush>
      <ul class="divide-y divide-line">
        <li
          v-for="row in rows"
          :key="row.id"
          :class="['py-3 pr-4', rowClass(row)]"
          :style="{ paddingLeft: `${1 + row.depth}rem` }"
        >
          <!-- phones: name on top, count and defect below, so nothing gets squeezed -->
          <div class="flex items-start gap-3">
            <input
              type="checkbox"
              class="mt-0.5 h-6 w-6 shrink-0 rounded border-line text-accent focus:ring-accent"
              :checked="!!row.checked_at"
              :aria-label="`${row.name} abhaken`"
              @change="row.checked_at ? reset(row) : check(row)"
            />
            <div class="min-w-0 flex-1 space-y-1">
              <p :class="['break-words', row.depth === 0 ? 'font-medium' : '']">
                <FontAwesomeIcon
                  v-if="row.is_container"
                  :icon="['fas', 'box']"
                  class="mr-1"
                />{{ row.name }}
              </p>
              <p class="text-xs text-ink-muted">
                <span class="whitespace-nowrap font-mono">{{
                  row.number
                }}</span>
                <template v-if="row.state_label">
                  · <strong>{{ row.state_label }}</strong></template
                >
                <template v-else-if="row.checked_at">
                  ·
                  {{ row.checked_by_scan ? "gescannt" : "von Hand" }}</template
                >
              </p>
              <p v-if="row.note" class="text-sm">{{ row.note }}</p>
              <!-- documented when it left: nobody answers for it now -->
              <p v-if="row.issue_defect" class="text-xs text-yellow-700">
                Schon bei der Ausgabe: {{ row.issue_defect }}
              </p>
              <p
                v-if="row.is_container && row.single_check"
                class="text-xs text-yellow-700"
              >
                Kam ungeprüft zurück: den Inhalt einzeln abhaken.
              </p>
              <p
                v-else-if="row.is_container && !inspect"
                class="text-xs text-ink-muted"
              >
                Abhaken hakt den Inhalt mit ab.
              </p>
              <div class="flex flex-wrap items-center gap-x-3 gap-y-2 pt-1">
                <label
                  v-if="row.kind === 'bulk'"
                  class="flex items-center gap-2 text-sm"
                >
                  <input
                    v-model="counts[row.id]"
                    type="number"
                    min="0"
                    :max="row.quantity"
                    inputmode="numeric"
                    :aria-label="`Gezählt von ${row.quantity}`"
                    @change="row.checked_at && check(row)"
                    class="w-20 rounded-md border-0 bg-surface py-1.5 text-ink ring-1 ring-inset ring-line"
                  />
                  <span class="whitespace-nowrap"
                    >von {{ row.quantity }} {{ row.unit
                    }}<template v-if="row.consumable">
                      (Verbrauch)</template
                    ></span
                  >
                </label>
                <UiButton
                  variant="secondary"
                  size="sm"
                  icon="triangle-exclamation"
                  @click="openDefect(row)"
                >
                  Mangel
                </UiButton>
              </div>
            </div>
          </div>
        </li>
      </ul>
      <UiEmptyState
        v-if="!rows.length"
        icon="check"
        title="Nichts mehr offen"
      />
    </UiCard>

    <!-- no time to count now: take everything back, the inspection follows later -->
    <UiCard v-if="!inspect" title="Keine Zeit zum Zählen?">
      <p class="text-sm text-ink-muted">
        Alles ungeprüft annehmen: beide unterschreiben jetzt, der Beleg trägt
        „ungeprüft, Mängel vorbehalten“; geprüft wird später unter Verleih ›
        Rückgaben.
      </p>
      <UiButton
        variant="secondary"
        icon="clock"
        class="mt-3"
        @click="startUnchecked"
      >
        Ungeprüft annehmen
      </UiButton>
    </UiCard>
  </div>

  <!-- step: what is booked, before anybody signs -->
  <UiCard
    v-else-if="current === 'summary'"
    :title="unchecked ? 'Ungeprüft annehmen' : 'Übersicht'"
  >
    <p v-if="unchecked" class="text-sm">
      Alle {{ rows.length }} Positionen werden ungeprüft angenommen und später
      geprüft.
    </p>
    <template v-else>
      <dl class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-md bg-surface-muted p-4">
          <dt class="text-sm text-ink-muted">Zurück und in Ordnung</dt>
          <dd class="text-2xl font-bold">{{ okRows.length }}</dd>
        </div>
        <div class="rounded-md bg-surface-muted p-4">
          <dt class="text-sm text-ink-muted">Mit Mangel</dt>
          <dd class="text-2xl font-bold">{{ defectRows.length }}</dd>
        </div>
        <div class="rounded-md bg-surface-muted p-4">
          <dt class="text-sm text-ink-muted">Bleibt ausgegeben</dt>
          <dd class="text-2xl font-bold">{{ openRows.length }}</dd>
        </div>
      </dl>
      <ul v-if="defectRows.length" class="mt-4 space-y-1 text-sm">
        <li v-for="row in defectRows" :key="row.id">
          <span class="font-mono">{{ row.number }}</span> {{ row.name }}:
          <strong>{{ row.state_label }}</strong
          ><template v-if="row.note"> – {{ row.note }}</template>
        </li>
      </ul>
      <p v-if="openRows.length" class="mt-4 text-sm text-ink-muted">
        Teilrückgabe: Was nicht abgehakt ist, bleibt ausgegeben; jede
        Teilrückgabe bekommt einen eigenen Beleg.
      </p>
    </template>
  </UiCard>

  <!-- step: close the inspection -->
  <UiCard v-else-if="inspect" title="Abschluss">
    <p class="text-sm text-ink-muted">
      Die Prüfung schließt die Buchung ab; jede Position ist abgehakt oder als
      Mangel vermerkt.
    </p>
    <UiButton
      class="mt-4 w-full"
      size="lg"
      icon="clipboard-check"
      :disabled="handledCount < rows.length"
      @click="completeInspection"
    >
      Prüfung abschließen
    </UiButton>
  </UiCard>

  <!-- step: signatures -->
  <UiCard v-else title="Unterschriften">
    <form class="space-y-6" @submit.prevent="complete(unchecked)">
      <UiInput
        v-model="form.returner"
        label="Rückgabe durch"
        required
        :error="form.errors.returner"
      />
      <template v-if="!loan.internal">
        <UiCheckbox v-model="form.on_paper" label="Auf Papier unterschrieben" />
        <div v-if="!form.on_paper" class="grid gap-6 lg:grid-cols-2">
          <LoansSignaturePad
            v-model="form.signatures.returner"
            label="Rückgabe durch"
            :name="form.returner"
            :error="form.errors['signatures.returner']"
          />
          <LoansSignaturePad
            v-model="form.signatures.receiver"
            label="Angenommen von"
            :name="receiver"
            :error="form.errors['signatures.receiver']"
          />
        </div>
      </template>
      <UiButton
        type="submit"
        size="lg"
        :icon="unchecked ? 'clock' : 'rotate-left'"
        class="w-full"
        :disabled="form.processing || (!unchecked && handledCount === 0)"
      >
        {{
          unchecked
            ? "Ungeprüft annehmen"
            : handledCount < rows.length
              ? `Teilrückgabe buchen (${handledCount})`
              : "Rückgabe buchen"
        }}
      </UiButton>
    </form>
  </UiCard>

  <!-- back and on, always at the same place -->
  <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
    <UiButton
      v-if="step > 0"
      variant="secondary"
      size="lg"
      icon="chevron-left"
      @click="back"
    >
      Zurück
    </UiButton>
    <span v-else />
    <div v-if="step < steps.length - 1" class="flex items-center gap-3">
      <span
        v-if="step === 0 && handledCount < rows.length"
        class="text-sm text-ink-muted"
      >
        {{ handledCount }} von {{ rows.length }} erledigt
      </span>
      <UiButton
        size="lg"
        icon="arrow-right"
        :disabled="step + 1 > reachable"
        @click="step++"
      >
        Weiter: {{ steps[step + 1].label }}
      </UiButton>
    </div>
  </div>

  <UiModal
    v-if="defectRow"
    :title="`Mangel: ${defectRow.name}`"
    @close="defectRow = null"
  >
    <form class="space-y-5" @submit.prevent="saveDefect">
      <UiSelect
        v-model="defectForm.state"
        label="Was ist los?"
        :options="states"
        :placeholder="null"
      />
      <UiSelect
        v-if="defectForm.state === 'damaged'"
        v-model="defectForm.condition_id"
        label="Neuer Zustand"
        :options="conditions"
        placeholder="bitte wählen"
      />
      <p v-if="defectForm.errors.condition_id" class="text-sm text-red-600">
        {{ defectForm.errors.condition_id }}
      </p>
      <UiTextarea
        v-model="defectForm.note"
        label="Notiz"
        required
        :error="defectForm.errors.note"
      />
      <UiPhotoPicker
        v-if="defectForm.state !== 'missing'"
        v-model="defectForm.photos"
        label="Fotos"
        :max="maxPhotos"
      />
      <UiButton type="submit" class="w-full" :disabled="defectForm.processing"
        >Vermerken</UiButton
      >
    </form>
  </UiModal>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { router, useForm, usePage } from "@inertiajs/vue3";
import type { LoanSummary } from "../../http";
import LoansScanInput from "../../components/ScanInput.vue";
import LoansSignaturePad from "../../components/SignaturePad.vue";
import LoansSteps from "../../components/Steps.vue";

const { kiosk, url } = useSurface();

type Row = {
  id: number;
  depth: number;
  number: string;
  name: string;
  kind: "single" | "bulk";
  unit: string | null;
  is_container: boolean;
  quantity: number;
  checked_at: string | null;
  checked_by_scan: boolean;
  returned_quantity: number | null;
  state: string | null;
  state_label: string | null;
  note: string | null;
  consumable: boolean;
  single_check: boolean;
  issue_defect: string | null;
};

/**
 * Return of an issued loan ("return") or inspection of what came back unchecked ("inspect") in
 * steps: tick off, count, mark defects with photos; look at the result; book with signatures.
 */
const props = defineProps<{
  mode: "return" | "inspect";
  loan: LoanSummary & { internal: boolean };
  rows: Row[];
  returner: string | null;
  conditions: Option[];
  states: Option[];
  maxPhotos: number;
}>();

const page = usePage<SharedProps>();
const inspect = computed(() => props.mode === "inspect");
const receiver = computed(() => page.props.auth?.user.name ?? "");
const errorMessages = computed(() =>
  ["loan", "code", "quantity", "photos"]
    .map((key) => page.props.errors?.[key])
    .filter(Boolean),
);

const handled = (row: Row) => row.checked_at !== null || row.state !== null;
const handledCount = computed(() => props.rows.filter(handled).length);
const okRows = computed(() =>
  props.rows.filter((row) => row.checked_at && !row.state),
);
const defectRows = computed(() => props.rows.filter((row) => row.state));
const openRows = computed(() => props.rows.filter((row) => !handled(row)));

// the steps: count, look at the result, sign; the inspection only checks and closes
const steps = computed(() =>
  inspect.value
    ? [
        { key: "material", label: "Prüfen" },
        { key: "close", label: "Abschluss" },
      ]
    : [
        { key: "material", label: "Material" },
        { key: "summary", label: "Übersicht" },
        { key: "sign", label: "Unterschriften" },
      ],
);
// "Ungeprüft annehmen" skips the counting
const unchecked = ref(false);
const reachable = computed(() => {
  if (inspect.value) {
    return props.rows.length > 0 && handledCount.value === props.rows.length
      ? 1
      : 0;
  }
  return unchecked.value || handledCount.value > 0 ? 2 : 0;
});
const step = ref(0);
const current = computed(() => steps.value[step.value].key);
const startUnchecked = () => {
  unchecked.value = true;
  step.value = 1;
};
const back = () => {
  step.value--;
  if (step.value === 0) {
    unchecked.value = false;
  }
};
watch(reachable, (value) => {
  if (step.value > value) {
    step.value = value;
  }
});
const rowClass = (row: Row) =>
  row.state
    ? "bg-red-50 dark:bg-red-950/40"
    : row.checked_at
      ? "bg-green-50 dark:bg-green-950/40"
      : "";

const counts = reactive<Record<number, number>>(
  Object.fromEntries(
    props.rows.map((row) => [row.id, row.returned_quantity ?? row.quantity]),
  ),
);
// after every answer the fields show what was stored, a field being typed in keeps its value
watch(
  () => props.rows,
  (rows) =>
    rows.forEach((row) => {
      if (row.returned_quantity !== null) {
        counts[row.id] = row.returned_quantity;
      }
    }),
);
// the counts of all bulk rows go along: a ticked box takes those of its contents
const bulkCounts = () =>
  Object.fromEntries(
    props.rows
      .filter(
        (row) =>
          row.kind === "bulk" &&
          !row.checked_at &&
          String(counts[row.id]) !== "",
      )
      .map((row) => [row.id, Number(counts[row.id])]),
  );
const base = computed(() => url(`/loans/${props.loan.id}/${props.mode}`));
const options = { preserveScroll: true, preserveState: true };

const scan = (code: string) => {
  const row = props.rows.find(
    (entry) =>
      entry.number.replace(/-/g, "") === code.toUpperCase().replace(/-/g, ""),
  );
  router.post(
    `${base.value}/check`,
    {
      code,
      quantity: row?.kind === "bulk" ? counts[row.id] : null,
      quantities: bulkCounts(),
    },
    options,
  );
};
const check = (row: Row) =>
  router.post(
    `${base.value}/check`,
    {
      row_id: row.id,
      quantity: row.kind === "bulk" ? counts[row.id] : null,
      quantities: bulkCounts(),
    },
    options,
  );
const reset = (row: Row) =>
  router.post(`${base.value}/rows/${row.id}/reset`, {}, options);

const defectRow = ref<Row | null>(null);
const defectForm = useForm({
  state: "missing",
  note: "",
  condition_id: null as number | null,
  photos: [] as File[],
});
const openDefect = (row: Row) => {
  defectForm.reset();
  defectRow.value = row;
};
const saveDefect = () =>
  defectForm
    .transform((data) => ({
      ...data,
      quantity:
        defectRow.value!.kind === "bulk" ? counts[defectRow.value!.id] : null,
    }))
    .post(`${base.value}/rows/${defectRow.value!.id}/defect`, {
      ...options,
      forceFormData: true,
      onSuccess: () => (defectRow.value = null),
    });

const form = useForm({
  returner: props.returner ?? "",
  on_paper: false,
  signatures: {
    returner: null as string | null,
    receiver: null as string | null,
  },
});
const complete = (unchecked: boolean) =>
  form.post(
    url(
      `/loans/${props.loan.id}/return/${unchecked ? "unchecked" : "complete"}`,
    ),
  );
const completeInspection = () =>
  router.post(url(`/loans/${props.loan.id}/inspect/complete`), {}, options);
</script>
