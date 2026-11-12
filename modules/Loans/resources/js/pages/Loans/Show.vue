<template>
  <UiPageHeader
    :title="loan.number"
    :description="loan.purpose ?? loan.kind_label"
  >
    <template v-if="loan.status === 'draft'">
      <UiButton v-if="can('loans.manage')" icon="check" @click="confirm"
        >Bestätigen</UiButton
      >
    </template>
    <UiButton
      v-if="
        loan.status === 'confirmed' && !loan.not_picked_up && can('loans.issue')
      "
      icon="right-from-bracket"
      :href="`/loans/${loan.id}/issue`"
    >
      Ausgeben
    </UiButton>
    <!-- the rest of a partial issue leaves later with a material list of its own -->
    <UiButton
      v-if="hasRest && can('loans.issue')"
      :icon="restExpired ? 'xmark' : 'right-from-bracket'"
      :variant="restExpired ? 'secondary' : 'primary'"
      :href="`/loans/${loan.id}/issue`"
    >
      {{ restExpired ? "Rest streichen" : "Rest ausgeben" }}
    </UiButton>
    <UiButton
      v-if="loan.status === 'issued' && can('loans.issue')"
      icon="rotate-left"
      :href="`/loans/${loan.id}/return`"
    >
      Zurücknehmen
    </UiButton>
    <UiButton
      v-if="loan.status === 'unchecked' && can('loans.issue')"
      icon="clipboard-check"
      :href="`/loans/${loan.id}/inspect`"
    >
      Prüfen
    </UiButton>
    <UiButton
      v-if="loan.status === 'issued' && can('loans.manage')"
      variant="secondary"
      icon="clock"
      @click="extendOpen = true"
    >
      Verlängern
    </UiButton>
    <!-- after the issue only purpose, notes and event change (see the form) -->
    <UiButton
      v-if="loan.status !== 'cancelled' && can('loans.manage')"
      variant="secondary"
      icon="pen"
      :href="`/loans/${loan.id}/edit`"
    >
      Bearbeiten
    </UiButton>
    <UiButton
      v-if="
        ['confirmed', 'issued'].includes(loan.status) && can('loans.manage')
      "
      variant="secondary"
      icon="file-pdf"
      :href="`/loans/${loan.id}/confirmation.pdf`"
      external
    >
      Buchungsbestätigung
    </UiButton>
    <UiButton
      v-if="
        loan.status === 'defects' &&
        !loan.defects_closed_at &&
        can('loans.cancel')
      "
      variant="secondary"
      icon="check-double"
      @click="defectsOpen = true"
    >
      Mängel abschließen
    </UiButton>
    <UiButton
      v-if="isOpen && can('loans.cancel')"
      variant="ghost"
      icon="ban"
      @click="cancelOpen = true"
      >Stornieren</UiButton
    >
    <UiButton
      v-if="loan.status === 'draft' && can('loans.manage')"
      variant="ghost"
      icon="trash"
      @click="deleteOpen = true"
      >Löschen</UiButton
    >
  </UiPageHeader>

  <div class="space-y-4">
    <UiAlert v-if="errorMessages.length" type="error">{{
      errorMessages.join(" ")
    }}</UiAlert>
    <UiAlert v-if="problems.length" type="warning">
      Zum Bestätigen fehlt noch: {{ problems.join(" ") }}
    </UiAlert>
    <UiAlert v-if="loan.overdue_days" type="error">
      Seit {{ loan.overdue_days }}
      {{ loan.overdue_days === 1 ? "Tag" : "Tagen" }} überfällig. Die
      Gegenstände bleiben belegt, bis die Rückgabe gebucht ist.
    </UiAlert>
    <UiAlert v-if="loan.not_picked_up" type="error">
      Nicht abgeholt: der Rückgabetermin ist vorbei. Die Buchung reserviert
      nichts mehr. Storniere sie oder gib ihr unter „Bearbeiten“ einen neuen
      Zeitraum.
    </UiAlert>
    <UiAlert v-if="restExpired" type="warning">
      Der Rest wurde nicht abgeholt und reserviert nichts mehr. Streiche ihn auf
      der Ausgabeseite oder verlängere die Buchung, um ihn noch auszugeben.
    </UiAlert>
    <UiAlert v-if="loan.status === 'cancelled'" type="warning">
      Storniert am <UiDateTime :value="loan.cancelled_at" />:
      {{ loan.cancelled_reason }}
    </UiAlert>
    <UiAlert v-if="loan.status === 'unchecked'" type="warning">
      Angenommen, ungeprüft. Die Prüfung ist offen; erst danach ist die Buchung
      abgeschlossen.
    </UiAlert>
    <UiAlert
      v-if="loan.status === 'defects'"
      :type="loan.defects_closed_at ? 'info' : 'error'"
    >
      <template v-if="loan.defects_closed_at"
        >Mängel abgeschlossen am <UiDateTime :value="loan.defects_closed_at" />:
        {{ loan.defects_note }}</template
      >
      <template v-else
        >Zurück mit Mängeln. Die Mängel sind offen, bis jemand sie mit einer
        Notiz abschließt.</template
      >
    </UiAlert>
    <!-- still missing: turns up ("Wieder da") or is written off when the defects are closed -->
    <UiCard
      v-if="missing.length"
      id="vermisst"
      title="Vermisst"
      description="Taucht etwas wieder auf: „Wieder da“. Beim Abschließen der Mängel wird der Rest als verloren ausgemustert."
      flush
    >
      <ul class="divide-y divide-line">
        <li
          v-for="row in missing"
          :key="row.id"
          class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 sm:px-6"
        >
          <div class="min-w-0">
            <p class="break-words font-medium">{{ row.name }}</p>
            <p class="text-sm text-ink-muted">
              <span class="whitespace-nowrap font-mono">{{ row.number }}</span>
              <template v-if="row.quantity">
                · {{ row.quantity }} {{ row.unit }}</template
              >
            </p>
          </div>
          <UiButton
            v-if="can('loans.issue')"
            variant="secondary"
            size="sm"
            icon="rotate-left"
            @click="found(row.id)"
          >
            Wieder da
          </UiButton>
        </li>
      </ul>
    </UiCard>
    <UiAlert v-if="pendingStow" type="info">
      {{ pendingStow }}
      {{ pendingStow === 1 ? "Gegenstand ist" : "Gegenstände sind" }} zurück,
      aber noch nicht eingeräumt.
      <UiLink href="/loans/returns" class="ml-1 font-bold">Einräumen</UiLink>
    </UiAlert>
  </div>

  <div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
      <UiCard title="Positionen" flush>
        <template #actions>
          <LoansStatusBadge :loan="loan" />
        </template>
        <ul v-if="positions.length" class="divide-y divide-line">
          <li
            v-for="position in positions"
            :key="position.id"
            class="space-y-1 px-4 py-3 sm:px-6"
          >
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="font-medium">
                  <FontAwesomeIcon
                    v-if="position.item.is_container"
                    :icon="['fas', 'box']"
                    class="mr-1 text-ink-muted"
                  />
                  <UiLink :href="`/inventory/items/${position.item.id}`">{{
                    position.item.name
                  }}</UiLink>
                </p>
                <p class="text-sm text-ink-muted">
                  <span class="font-mono">{{ position.item.number }}</span>
                  <template v-if="position.item.kind === 'bulk'">
                    · {{ position.quantity }} {{ position.item.unit }}</template
                  >
                  · {{ position.condition }}
                  <template v-if="position.return_state">
                    · {{ position.return_state }}</template
                  >
                  <template
                    v-if="
                      ['issued', 'unchecked', 'closed', 'defects'].includes(
                        loan.status,
                      ) && !position.issued_at
                    "
                  >
                    · <strong>Rest, noch nicht ausgegeben</strong></template
                  >
                </p>
                <p v-if="position.issue_defect" class="text-sm text-yellow-700">
                  Mangel bei der Ausgabe: {{ position.issue_defect }}
                </p>
                <p v-if="position.return_note" class="text-sm">
                  {{ position.return_note }}
                </p>
              </div>
              <div
                v-if="isOpen && can('loans.manage')"
                class="flex items-center gap-2"
              >
                <input
                  v-if="position.item.kind === 'bulk'"
                  :value="position.quantity"
                  type="number"
                  min="1"
                  :max="position.item.stock"
                  inputmode="numeric"
                  aria-label="Menge"
                  class="w-20 rounded-md border-0 bg-surface py-1.5 text-ink ring-1 ring-inset ring-line"
                  @change="
                    setQuantity(
                      position.id,
                      ($event.target as HTMLInputElement).value,
                    )
                  "
                />
                <UiButton
                  variant="ghost"
                  size="sm"
                  icon="xmark"
                  :aria-label="`${position.item.name} entfernen`"
                  @click="remove(position.id)"
                />
              </div>
            </div>
            <!-- availability in the time of the loan -->
            <template v-if="position.check">
              <p v-if="position.check.problem" class="text-sm text-red-600">
                {{ position.check.problem }}
              </p>
              <p
                v-for="conflict in position.check.conflicts"
                :key="conflict.id"
                class="text-sm text-red-600"
              >
                Kollision mit
                <UiLink :href="`/loans/${conflict.id}`">{{
                  conflict.title
                }}</UiLink
                >, {{ formatDateTime(conflict.pickup_at) }} bis
                {{
                  conflict.overdue
                    ? "zur Rückgabe (überfällig)"
                    : formatDateTime(conflict.return_at)
                }}.
              </p>
              <p
                v-if="position.check.free !== null && position.check.blocked"
                class="text-sm text-red-600"
              >
                Frei sind nur {{ position.check.free }}
                {{ position.item.unit }}.
              </p>
              <p v-if="position.check.next_free" class="text-sm">
                Nächster freier Termin:
                {{ formatDateTime(position.check.next_free) }}
              </p>
              <p
                v-for="draft in position.check.drafts"
                :key="draft.id"
                class="text-sm text-ink-muted"
              >
                Vorgemerkt im Entwurf
                <UiLink :href="`/loans/${draft.id}`">{{ draft.number }}</UiLink>
                ({{ formatDateTime(draft.pickup_at) }} bis
                {{ formatDateTime(draft.return_at)
                }}<template v-if="draft.created_by"
                  >, {{ draft.created_by }}</template
                >)
              </p>
              <p
                v-for="hint in position.check.hints"
                :key="hint"
                class="text-sm text-yellow-700"
              >
                {{ hint }}
              </p>
            </template>
            <p
              v-for="check in position.box_checks"
              :key="check.checked_at"
              class="text-sm text-ink-muted"
            >
              <FontAwesomeIcon
                :icon="['fas', 'clipboard-check']"
                class="mr-1"
              />
              Kontrolliert von {{ check.name }} am
              <UiDateTime :value="check.checked_at" /> ({{ check.ticked }} von
              {{ check.total }} abgehakt)
            </p>
          </li>
        </ul>
        <UiEmptyState
          v-else
          icon="boxes-stacked"
          title="Noch keine Positionen"
        />
        <div
          v-if="isOpen && can('loans.manage')"
          class="border-t border-line p-4 sm:px-6"
        >
          <LoansItemPicker :loan-id="loan.id" />
        </div>
      </UiCard>

      <UiCard
        title="Materialliste"
        description="Positionen mit dem Inhalt der Boxen; bis zur Ausgabe der aktuelle Stand der Boxen."
        flush
      >
        <table class="min-w-full divide-y divide-line text-sm">
          <tbody class="divide-y divide-line">
            <tr v-for="(row, index) in contents" :key="index">
              <td
                class="whitespace-nowrap py-2 pr-2 font-mono text-xs"
                :style="{ paddingLeft: `${1 + row.depth * 1.25}rem` }"
              >
                {{ row.number }}
              </td>
              <td :class="['py-2', row.depth === 0 ? 'font-medium' : '']">
                {{ row.name }}
              </td>
              <td class="whitespace-nowrap py-2">
                {{ row.bulk ? `${row.quantity} ${row.unit}` : "" }}
              </td>
              <td class="whitespace-nowrap py-2 pr-4 text-ink-muted">
                {{ row.condition }}
              </td>
            </tr>
          </tbody>
        </table>
      </UiCard>
    </div>

    <div class="order-first space-y-6 lg:order-none">
      <UiCard title="Daten">
        <dl class="space-y-3 text-sm">
          <div>
            <dt class="text-ink-muted">Zeitraum</dt>
            <dd>
              {{ formatDateTime(loan.pickup_at) }} bis
              {{ formatDateTime(loan.return_at) }}
            </dd>
          </div>
          <div>
            <dt class="text-ink-muted">
              {{
                loan.kind === "internal"
                  ? "Verantwortliches Mitglied"
                  : "Verantwortliche Person"
              }}
            </dt>
            <dd>
              <UiLink
                v-if="loan.responsible?.id && can('loans.manage')"
                :href="`/loans/contacts/${loan.responsible.id}`"
                >{{ loan.responsible.name }}</UiLink
              >
              <template v-else>{{ loan.responsible?.name ?? "–" }}</template>
              <template v-if="loan.responsible?.email">
                <br />{{ loan.responsible.email }} · {{ loan.responsible.phone
                }}<br />{{ loan.responsible.address }}
              </template>
            </dd>
          </div>
          <div
            v-if="loan.borrower && loan.borrower.id !== loan.responsible?.id"
          >
            <dt class="text-ink-muted">Entleiher</dt>
            <dd>
              {{ loan.borrower.name
              }}<template v-if="loan.borrower.affiliation">
                ({{ loan.borrower.affiliation }})</template
              >
            </dd>
          </div>
          <div v-if="loan.event">
            <dt class="text-ink-muted">Veranstaltung</dt>
            <dd>
              <UiLink :href="`/loans/events/${loan.event.id}`">{{
                loan.event.name
              }}</UiLink>
            </dd>
          </div>
          <div v-if="loan.issuer">
            <dt class="text-ink-muted">Ausgegeben von</dt>
            <dd>{{ loan.issuer }} am <UiDateTime :value="loan.issued_at" /></dd>
          </div>
          <div v-if="loan.notes">
            <dt class="text-ink-muted">Notiz</dt>
            <dd class="whitespace-pre-line">{{ loan.notes }}</dd>
          </div>
          <div>
            <dt class="text-ink-muted">Angelegt von</dt>
            <dd>{{ loan.creator ?? "–" }}</dd>
          </div>
        </dl>
      </UiCard>

      <UiCard v-if="loan.pack_pin" title="Pack-PIN">
        <p class="font-mono text-2xl font-semibold tracking-widest">
          {{ loan.pack_pin }}
        </p>
        <p class="mt-1 text-sm text-ink-muted">
          Damit bestätigt jemand ohne Konto auf der Seite einer Box dieser
          Buchung, dass er sie kontrolliert hat.
        </p>
        <div class="mt-3 flex flex-wrap gap-2">
          <UiButton
            variant="secondary"
            size="sm"
            icon="dice"
            @click="newPin(null)"
            >Neu würfeln</UiButton
          >
          <UiButton
            variant="secondary"
            size="sm"
            icon="pen"
            @click="pinOpen = true"
            >Selbst festlegen</UiButton
          >
        </div>
      </UiCard>

      <UiCard v-if="documents.length" title="Dokumente" flush>
        <ul class="divide-y divide-line">
          <li
            v-for="document in documents"
            :key="document.id"
            class="space-y-1 px-4 py-3 sm:px-6"
          >
            <div class="flex items-center justify-between gap-2">
              <a
                :href="document.url"
                class="font-medium text-accent hover:underline"
              >
                <FontAwesomeIcon :icon="['fas', 'file-pdf']" class="mr-1" />{{
                  document.label
                }}
              </a>
              <span class="text-xs text-ink-muted"
                ><UiDateTime :value="document.created_at"
              /></span>
            </div>
            <p v-if="document.unchecked" class="text-sm text-yellow-700">
              ungeprüft, Mängel vorbehalten
            </p>
            <p
              v-for="signature in document.signatures"
              :key="signature.role"
              class="text-sm text-ink-muted"
            >
              {{ signature.role }}: {{ signature.name
              }}<template v-if="signature.on_paper"> (auf Papier)</template>
            </p>
            <p
              v-if="document.unchanged === true"
              class="text-sm text-green-700"
            >
              <FontAwesomeIcon
                :icon="['fas', 'circle-check']"
                class="mr-1"
              />unverändert seit der Unterschrift
            </p>
            <p
              v-else-if="document.unchanged === false"
              class="text-sm text-red-600"
            >
              <FontAwesomeIcon
                :icon="['fas', 'circle-xmark']"
                class="mr-1"
              />Der gespeicherte Stand passt nicht mehr zur Prüfsumme.
            </p>
            <p
              v-if="document.hash"
              class="break-all font-mono text-[0.65rem] text-ink-muted"
            >
              SHA-256 {{ document.hash }}
            </p>
          </li>
        </ul>
      </UiCard>
    </div>
  </div>

  <UiModal v-if="cancelOpen" title="Stornieren" @close="cancelOpen = false">
    <form class="space-y-5" @submit.prevent="cancel">
      <UiTextarea
        v-model="cancelForm.reason"
        label="Grund"
        required
        :error="cancelForm.errors.reason"
      />
      <UiButton
        type="submit"
        variant="danger"
        class="w-full"
        :disabled="cancelForm.processing"
        >Stornieren</UiButton
      >
    </form>
  </UiModal>

  <UiModal v-if="extendOpen" title="Verlängern" @close="extendOpen = false">
    <form class="space-y-5" @submit.prevent="extend">
      <UiInput
        v-model="extendForm.return_at"
        label="Neuer Rückgabetermin"
        type="datetime-local"
        required
        :error="extendForm.errors.return_at"
      />
      <UiButton type="submit" class="w-full" :disabled="extendForm.processing"
        >Verlängern</UiButton
      >
    </form>
  </UiModal>

  <UiModal v-if="pinOpen" title="Pack-PIN festlegen" @close="pinOpen = false">
    <form class="space-y-5" @submit.prevent="newPin(pinForm.pin)">
      <UiInput
        v-model="pinForm.pin"
        label="Sechs Ziffern"
        inputmode="numeric"
        required
        :error="pinForm.errors.pin"
      />
      <UiButton type="submit" class="w-full" :disabled="pinForm.processing"
        >Speichern</UiButton
      >
    </form>
  </UiModal>

  <UiModal
    v-if="defectsOpen"
    title="Mängel abschließen"
    @close="defectsOpen = false"
  >
    <form class="space-y-5" @submit.prevent="closeDefects">
      <UiAlert v-if="missing.length" type="warning">
        Noch vermisst und wird als verloren ausgemustert:
        {{
          missing
            .map(
              (row) =>
                `${row.number} ${row.name}${row.quantity ? ` (${row.quantity} ${row.unit})` : ""}`,
            )
            .join(", ")
        }}.
      </UiAlert>
      <UiTextarea
        v-model="defectsForm.note"
        label="Notiz"
        placeholder="z. B. Ersatz bezahlt, Kabel nachgekauft"
        required
        :error="defectsForm.errors.note"
      />
      <UiButton type="submit" class="w-full" :disabled="defectsForm.processing"
        >Abschließen</UiButton
      >
    </form>
  </UiModal>

  <UiConfirmModal
    v-if="deleteOpen"
    title="Entwurf löschen"
    :message="`${loan.number} wird gelöscht.`"
    @close="deleteOpen = false"
    @confirm="destroy"
  />
</template>

<script setup lang="ts">
import { computed, ref } from "vue";
import { router, useForm, usePage } from "@inertiajs/vue3";
import { formatDateTime, type Contact, type LoanSummary } from "../../http";
import LoansStatusBadge from "../../components/StatusBadge.vue";
import LoansItemPicker from "../../components/ItemPicker.vue";

type LoanRef = {
  id: number;
  number: string;
  title: string;
  pickup_at: string;
  return_at: string;
  overdue?: boolean;
  created_by?: string | null;
};

/**
 * Page of a loan: positions with availability, material list, data, pack pin and documents, with
 * the steps the status allows.
 */
const props = defineProps<{
  loan: LoanSummary & {
    purpose: string | null;
    notes: string | null;
    kind_label: string;
    cancelled_reason: string | null;
    defects_note: string | null;
    borrower: Contact | null;
    responsible: Contact | { id: null; name: string | null } | null;
    issuer: string | null;
    creator: string | null;
    event: { id: number; name: string } | null;
    issued_at: string | null;
    cancelled_at: string | null;
    defects_closed_at: string | null;
    pack_pin: string | null;
  };
  positions: {
    id: number;
    item: {
      id: number;
      number: string;
      name: string;
      unit: string | null;
      kind: string;
      is_container: boolean;
      stock: number;
    };
    quantity: number;
    condition: string;
    return_state: string | null;
    issued_at: string | null;
    issue_defect: string | null;
    return_note: string | null;
    check: {
      problem: string | null;
      conflicts: LoanRef[];
      drafts: LoanRef[];
      hints: string[];
      free: number | null;
      next_free: string | null;
      blocked: boolean;
    } | null;
    box_checks: {
      name: string;
      checked_at: string;
      ticked: number;
      total: number;
    }[];
  }[];
  contents: {
    number: string;
    name: string;
    quantity: number;
    unit: string | null;
    bulk: boolean;
    condition: string;
    depth: number;
  }[];
  problems: string[];
  documents: {
    id: number;
    label: string;
    created_at: string;
    unchanged: boolean | null;
    hash: string | null;
    unchecked: boolean;
    signatures: { role: string; name: string; on_paper: boolean }[];
    url: string;
  }[];
  pendingStow: number;
  hasRest: boolean;
  restExpired: boolean;
  missing: {
    id: number;
    number: string;
    name: string;
    quantity: number | null;
    unit: string | null;
  }[];
}>();

const page = usePage<SharedProps>();
const { can } = usePermissions();
const isOpen = computed(() =>
  ["draft", "confirmed"].includes(props.loan.status),
);
const errorMessages = computed(() =>
  ["loan", "item", "quantity", "pin", "row"]
    .map((key) => page.props.errors?.[key])
    .filter(Boolean),
);

const options = { preserveScroll: true };
const found = (rowId: number) =>
  router.post(`/loans/${props.loan.id}/missing/${rowId}/found`, {}, options);
const confirm = () =>
  router.post(`/loans/${props.loan.id}/confirm`, {}, options);
const remove = (id: number) =>
  router.delete(`/loans/${props.loan.id}/positions/${id}`, options);
const setQuantity = (id: number, quantity: string) =>
  router.put(
    `/loans/${props.loan.id}/positions/${id}`,
    { quantity: Number(quantity) },
    options,
  );

const cancelOpen = ref(false);
const cancelForm = useForm({ reason: "" });
const cancel = () =>
  cancelForm.post(`/loans/${props.loan.id}/cancel`, {
    ...options,
    onSuccess: () => (cancelOpen.value = false),
  });

const extendOpen = ref(false);
const extendForm = useForm({ return_at: props.loan.return_at.slice(0, 16) });
const extend = () =>
  extendForm.post(`/loans/${props.loan.id}/extend`, {
    ...options,
    onSuccess: () => (extendOpen.value = false),
  });

const pinOpen = ref(false);
const pinForm = useForm({ pin: "" });
const newPin = (pin: string | null) =>
  pinForm
    .transform(() => ({ pin }))
    .post(`/loans/${props.loan.id}/pin`, {
      ...options,
      onSuccess: () => (pinOpen.value = false),
    });

const defectsOpen = ref(false);
const defectsForm = useForm({ note: "" });
const closeDefects = () =>
  defectsForm.post(`/loans/${props.loan.id}/close-defects`, {
    ...options,
    onSuccess: () => (defectsOpen.value = false),
  });

const deleteOpen = ref(false);
const destroy = () => router.delete(`/loans/${props.loan.id}`);
</script>
