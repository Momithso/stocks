<template>
  <UiPageHeader
    :title="`${loan.supplement ? 'Nachausgabe' : 'Ausgabe'} ${loan.number}`"
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

  <div class="mb-4 space-y-4">
    <!-- after the return time nothing is held: drop the rest, or a new time first -->
    <UiAlert v-if="loan.expired" type="warning">
      <template v-if="loan.supplement"
        >Der Rückgabetermin ist vorbei, der Rest wurde nicht abgeholt. Streiche
        ihn unten oder verlängere die Buchung, um ihn noch auszugeben.</template
      >
      <template v-else
        >Der Rückgabetermin ist vorbei, die Buchung wurde nicht abgeholt. Gib
        ihr erst einen neuen Zeitraum oder storniere sie.</template
      >
    </UiAlert>
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

  <!-- step: tick off the material -->
  <div v-if="current === 'material'" class="space-y-6">
    <UiCard
      title="Material abhaken"
      :description="`${checkedCount} von ${positionCount} Positionen abgehakt. Scanne jedes Teil oder hake es von Hand ab; der Inhalt einer Box kann einzeln abgehakt werden, muss aber nicht. Was nicht abgehakt ist, bleibt als Rest (Teilausgabe); ein Teil aus einer abgehakten Box ohne Haken bleibt hier und kommt aus der Box. Fällt dir ein Mangel auf, vermerke ihn an der Zeile.`"
    >
      <LoansScanInput @scan="scan" />
    </UiCard>

    <UiAlert v-if="loan.early" type="info">
      Abholung vor dem Termin ({{ formatDateTime(loan.pickup_at) }}). Die
      Leihzeit beginnt jetzt; was bis dahin noch anderweitig gebucht ist, steht
      rot an der Zeile und kann nur später mitgehen.
    </UiAlert>

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
              <p :class="['break-words', row.depth === 0 ? 'font-medium' : '']">
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
                  {{ row.checked_by_scan ? "gescannt" : "von Hand" }}</template
                >
                <UiBadge v-if="staysHere(row)" variant="warning" class="ml-1"
                  >bleibt hier</UiBadge
                >
              </p>
              <p
                v-for="note in [...row.box_checks, ...row.hints]"
                :key="note"
                class="text-xs text-yellow-700"
              >
                {{ note }}
              </p>
              <p v-if="row.early" class="text-sm text-red-700">
                <FontAwesomeIcon :icon="['fas', 'clock']" class="mr-1" />{{
                  row.early
                }}
              </p>
              <p v-if="row.issue_defect" class="text-sm text-red-700">
                <FontAwesomeIcon
                  :icon="['fas', 'triangle-exclamation']"
                  class="mr-1"
                />Mangel: {{ row.issue_defect }}
              </p>
              <p
                v-if="row.is_container && !row.hints.length"
                class="text-xs text-ink-muted"
              >
                Abhaken hakt den Inhalt mit ab. Ein Teil nicht mitgeben: seinen
                Haken wegnehmen, es bleibt am Platz der Box.
              </p>
              <div class="flex flex-wrap items-center gap-x-3 gap-y-2 pt-1">
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
      <p
        v-if="leftBehind.length"
        class="border-t border-line px-4 py-3 text-sm text-yellow-800 dark:text-yellow-200 sm:px-6"
      >
        Bleibt hier und kommt aus der Box:
        {{ leftBehind.map((row) => `${row.number} ${row.name}`).join(", ") }}.
        Es steht danach am Platz der Box und nicht auf der Materialliste.
      </p>
      <p
        v-if="missing.length"
        class="border-t border-line px-4 py-3 text-sm text-ink-muted sm:px-6"
      >
        Nicht abgehakt: {{ missing.map((row) => row.number).join(", ") }}. Das
        bleibt als Rest; im nächsten Schritt wählst du, ob es später mitgeht
        oder gestrichen wird.
      </p>
    </UiCard>
  </div>

  <!-- step: what does not leave now (partial issue, no defect) -->
  <UiCard
    v-else-if="current === 'rest'"
    title="Rest"
    description="Diese Positionen gehen jetzt nicht mit. Eine Teilausgabe ist kein Mangel. Was später mitgeht, bleibt gebucht und wird mit einer eigenen Materialliste nachausgegeben."
  >
    <ul class="divide-y divide-line">
      <li
        v-for="row in missing"
        :key="row.id"
        class="flex flex-wrap items-center justify-between gap-3 py-3"
      >
        <span class="min-w-0">
          <span class="font-medium">{{ row.name }}</span>
          <span class="block text-sm text-ink-muted">
            <span class="font-mono">{{ row.number }}</span>
            <template v-if="row.kind === 'bulk'">
              · {{ row.quantity }} {{ row.unit }}</template
            >
            <template v-if="row.issue_defect">
              · Mangel: {{ row.issue_defect }}</template
            >
          </span>
        </span>
        <span class="flex items-center gap-2">
          <UiBadge variant="accent">Später ausgeben</UiBadge>
          <UiButton
            variant="ghost"
            size="sm"
            icon="xmark"
            @click="dropping = row"
          >
            Streichen
          </UiButton>
        </span>
      </li>
    </ul>
  </UiCard>

  <!-- step: who picks it up -->
  <UiCard v-else-if="current === 'person'" title="Wer holt ab?">
    <p v-if="!changingResponsible" class="text-sm">
      <strong>{{ loan.responsible?.name }}</strong
      ><br />
      {{ loan.responsible?.email }} · {{ loan.responsible?.phone }}<br />
      {{ loan.responsible?.address }}
    </p>
    <p v-if="loan.responsible?.missing?.length" class="text-sm text-red-600">
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

  <!-- step: documents to read before signing -->
  <UiCard
    v-else-if="current === 'documents'"
    title="Dokumente"
    :description="
      loan.supplement
        ? 'Materialliste des Nachtrags ansehen; der Leihvertrag der ersten Ausgabe gilt weiter.'
        : 'Leihvertrag und Materialliste ansehen; die verantwortliche Person bestätigt sie mit der Unterschrift.'
    "
  >
    <div class="flex flex-wrap gap-2">
      <UiButton
        v-if="!loan.internal && !loan.supplement"
        variant="secondary"
        size="sm"
        icon="file-pdf"
        :href="url(`/loans/${loan.id}/preview/contract.pdf`)"
        external
        >Leihvertrag</UiButton
      >
      <UiButton
        variant="secondary"
        size="sm"
        icon="file-pdf"
        :href="url(`/loans/${loan.id}/preview/list.pdf`)"
        external
        >Materialliste</UiButton
      >
    </div>
    <details v-if="contract && !loan.supplement" class="mt-3 text-sm">
      <summary class="cursor-pointer font-medium">Vertrag hier lesen</summary>
      <!-- html from the server: escaped text with headings and lists (ContractTemplate::render) -->
      <div
        class="contract mt-2 max-h-96 space-y-2 overflow-y-auto rounded bg-surface-muted p-3"
        v-html="contract"
      />
    </details>
  </UiCard>

  <!-- step: signatures, only once everything is ticked off -->
  <UiCard v-else :title="loan.internal ? 'Abschluss' : 'Unterschriften'">
    <form class="space-y-6" @submit.prevent="complete">
      <template v-if="!loan.internal">
        <UiCheckbox
          v-model="form.on_paper"
          label="Auf Papier unterschrieben"
          description="Beide PDFs drucken, mit Stift unterschreiben; ein Foto oder Scan lässt sich anhängen."
        />
        <div v-if="!form.on_paper" class="grid gap-6 lg:grid-cols-2">
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
        </div>
        <label v-else class="block text-sm">
          <span class="font-medium">Foto oder Scan des Blatts (optional)</span>
          <input
            type="file"
            accept="image/jpeg,image/png,image/webp,application/pdf"
            class="mt-1 block w-full text-sm"
            @change="
              form.scan = ($event.target as HTMLInputElement).files?.[0] ?? null
            "
          />
        </label>
      </template>
      <p v-else class="text-sm text-ink-muted">
        Eigenbedarf: ohne Vertrag und Unterschrift, nur mit der Materialliste.
      </p>
      <UiButton
        type="submit"
        size="lg"
        icon="right-from-bracket"
        class="w-full"
        :disabled="form.processing || checkedCount === 0"
      >
        {{
          missing.length
            ? `${checkedCount} von ${positionCount} ausgeben (Teilausgabe)`
            : "Ausgeben"
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
      @click="step--"
    >
      Zurück
    </UiButton>
    <span v-else />
    <div v-if="step < steps.length - 1" class="flex items-center gap-3">
      <span v-if="step === 0 && missing.length" class="text-sm text-ink-muted">
        {{ missing.length }} bleiben als Rest
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

  <!-- defect found now: documented, "beschädigt" changes the condition -->
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
        v-model="defectForm.photos"
        label="Fotos"
        :max="maxPhotos"
      />
      <UiCheckbox
        v-if="defectRow.depth === 0"
        v-model="defectForm.keep_back"
        label="Nicht ausgeben"
        description="Bleibt hier als Rest; später nachausgeben oder streichen."
      />
      <div class="flex flex-wrap gap-2">
        <UiButton type="submit" :disabled="defectForm.processing"
          >Vermerken</UiButton
        >
        <UiButton
          v-if="defectRow.issue_state"
          variant="ghost"
          icon="xmark"
          @click="resetDefect"
        >
          Mangel entfernen
        </UiButton>
      </div>
    </form>
  </UiModal>

  <UiConfirmModal
    v-if="dropping"
    title="Aus der Buchung streichen"
    :message="`${dropping.number} ${dropping.name} geht nicht mit und wird aus der Buchung gestrichen.`"
    @close="dropping = null"
    @confirm="drop"
  />
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { router, useForm, usePage } from "@inertiajs/vue3";
import { formatDateTime, type Contact, type LoanSummary } from "../../http";
import LoansScanInput from "../../components/ScanInput.vue";
import LoansSignaturePad from "../../components/SignaturePad.vue";
import LoansContactPicker from "../../components/ContactPicker.vue";
import LoansSteps from "../../components/Steps.vue";

const { kiosk, url } = useSurface();

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
  issue_state: string | null;
  issue_defect: string | null;
  // picked up early: why the row can not leave yet
  early: string | null;
};

/**
 * Issue of a confirmed loan in steps: tick off the material (defects noted at the row), rest,
 * responsible person, documents, signatures. What is not ticked off stays as rest (partial issue)
 * and leaves later the same way ("Nachausgabe") or is dropped. The ticks are stored on the
 * server at once, so the issue can go on on another device.
 */
const props = defineProps<{
  loan: LoanSummary & {
    internal: boolean;
    responsible: Contact | null;
    borrower: string | null;
    supplement: boolean;
    expired: boolean;
    early: boolean;
  };
  rows: Row[];
  states: Option[];
  conditions: Option[];
  maxPhotos: number;
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
  ["loan", "code", "quantity", "item", "responsible_contact_id", "state"]
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
// a row of the contents without tick whose box is ticked: the box leaves, the row stays here
const staysHere = (row: Row) =>
  row.depth > 0 &&
  !row.checked_at &&
  !!props.rows.find((other) => other.id === row.parent_id)?.checked_at;
const leftBehind = computed(() => props.rows.filter(staysHere));

// the steps of the issue: the rest only when something stays here, the person only the first
// time (a supplement is signed by the same person); after the material once something is ticked
// after the return time a rest can only be dropped
const steps = computed(() =>
  props.loan.expired && props.loan.supplement
    ? [{ key: "rest", label: "Rest" }]
    : [
        { key: "material", label: "Material" },
        { key: "rest", label: "Rest" },
        { key: "person", label: "Person" },
        { key: "documents", label: "Dokumente" },
        {
          key: "sign",
          label: props.loan.internal ? "Abschluss" : "Unterschriften",
        },
      ].filter(
        (entry) =>
          !(entry.key === "rest" && missing.value.length === 0) &&
          !(
            entry.key === "person" &&
            (props.loan.internal || props.loan.supplement)
          ),
      ),
);
const reachable = computed(() =>
  checkedCount.value > 0 ? steps.value.length - 1 : 0,
);
// a page opened again with everything ticked off goes on behind the material
const step = ref(missing.value.length === 0 && reachable.value > 0 ? 1 : 0);
const current = computed(
  () => steps.value[Math.min(step.value, steps.value.length - 1)].key,
);
// unticking something on another device leads back to the material
watch(reachable, (value) => {
  if (step.value > value) {
    step.value = value;
  }
});

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
    url(`/loans/${props.loan.id}/issue/check`),
    { code, quantity: row?.kind === "bulk" ? quantities[row.id] : null },
    options,
  );
};
const toggle = (row: Row) =>
  row.checked_at
    ? router.post(
        url(`/loans/${props.loan.id}/issue/rows/${row.id}/uncheck`),
        {},
        options,
      )
    : router.post(
        url(`/loans/${props.loan.id}/issue/check`),
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
    url(`/loans/${props.loan.id}/issue/check`),
    { row_id: row.id, quantity: quantities[row.id] },
    options,
  );
// rest: dropped from the loan for good
const dropping = ref<Row | null>(null);
const drop = () =>
  router.post(
    url(`/loans/${props.loan.id}/issue/rows/${dropping.value!.id}/drop`),
    {},
    { ...options, onFinish: () => (dropping.value = null) },
  );

// defect found at the issue
const defectRow = ref<Row | null>(null);
const defectForm = useForm({
  state: "damaged",
  note: "",
  condition_id: null as number | null,
  photos: [] as File[],
  keep_back: false,
});
const openDefect = (row: Row) => {
  defectForm.reset();
  defectForm.clearErrors();
  if (row.issue_state) {
    defectForm.state = row.issue_state;
  }
  defectRow.value = row;
};
const saveDefect = () =>
  defectForm.post(
    url(`/loans/${props.loan.id}/issue/rows/${defectRow.value!.id}/defect`),
    {
      ...options,
      forceFormData: true,
      onSuccess: () => (defectRow.value = null),
    },
  );
const resetDefect = () =>
  router.post(
    url(
      `/loans/${props.loan.id}/issue/rows/${defectRow.value!.id}/reset-defect`,
    ),
    {},
    { ...options, onSuccess: () => (defectRow.value = null) },
  );
const addForeign = () => {
  if (props.foreign) {
    router.post(
      url(`/loans/${props.loan.id}/positions`),
      { item_id: props.foreign.id },
      {
        preserveScroll: true,
        onSuccess: () => router.visit(url(`/loans/${props.loan.id}/issue`)),
      },
    );
  }
};

const changingResponsible = ref(false);
const newResponsible = ref<Contact | null>(null);
const saveResponsible = () =>
  router.post(
    url(`/loans/${props.loan.id}/issue/responsible`),
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
  form.post(url(`/loans/${props.loan.id}/issue/complete`), {
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
