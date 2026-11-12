<template>
  <UiPageHeader
    title="Verleih"
    description="Leihvertrag, Verleiher, Puffer zwischen Buchungen, Buchungsnummern und Fachschaften."
  />

  <div class="grid gap-6 lg:grid-cols-2">
    <form class="space-y-6" @submit.prevent="save">
      <UiCard title="Allgemein">
        <div class="space-y-5">
          <UiTextarea
            v-model="form.loans.lender"
            label="Verleiher im Vertrag"
            :rows="2"
            hint="Die richtige Bezeichnung klärt ihr mit dem AStA."
            :error="form.errors['loans.lender']"
          />
          <UiInput
            v-model="form.loans.place"
            label="Ort der Unterschrift"
            hint="Leer: der Ort aus der Anschrift der Organisation."
            :error="form.errors['loans.place']"
          />
          <div class="grid gap-4 sm:grid-cols-2">
            <UiInput
              v-model="form.loans.buffer_hours"
              label="Puffer in Stunden"
              type="number"
              inputmode="numeric"
              hint="Bleibt zwischen der Rückgabe einer Buchung und der Abholung der nächsten frei, z. B. zum Prüfen und Einräumen. 0: direkt anschließend."
              :error="form.errors['loans.buffer_hours']"
            />
            <UiInput
              v-model="form.loans.number_prefix"
              label="Präfix der Buchungsnummer"
              hint="z. B. B für B-2026-0042"
              :error="form.errors['loans.number_prefix']"
            />
          </div>
        </div>
      </UiCard>

      <UiCard
        title="Verleihseite und Nachrichten"
        description="Seite für Entleiher ohne Konto, E-Mails und Push."
      >
        <div class="grid gap-5 sm:grid-cols-2">
          <UiInput
            v-model="form.loans.page_days_after_close"
            label="Link gilt nach Abschluss noch (Tage)"
            type="number"
            min="1"
            max="365"
            hint="Während der Leihe läuft der Link nie ab."
            :error="form.errors['loans.page_days_after_close']"
          />
          <UiInput
            v-model="form.loans.overdue_days"
            label="Überfällig-Nachrichten nach (Tagen)"
            placeholder="1, 3, 7"
            hint="Ab der zweiten Stufe auch an die Beteiligten im FSR."
            :error="form.errors['loans.overdue_days']"
          />
          <UiInput
            v-model="form.loans.remind_pickup_hours"
            label="Erinnerung vor der Abholung (Stunden)"
            type="number"
            min="0"
            max="336"
            hint="0 schaltet sie aus."
            :error="form.errors['loans.remind_pickup_hours']"
          />
          <UiInput
            v-model="form.loans.remind_return_hours"
            label="Erinnerung vor der Rückgabe (Stunden)"
            type="number"
            min="0"
            max="336"
            hint="0 schaltet sie aus."
            :error="form.errors['loans.remind_return_hours']"
          />
          <UiTextarea
            v-model="form.loans.pickup_place"
            label="Abholort"
            class="sm:col-span-2"
            :rows="2"
            hint="Steht auf der Verleihseite und in den Nachrichten. Leer: die Anschrift der Organisation."
            :error="form.errors['loans.pickup_place']"
          />
        </div>
        <details class="mt-5">
          <summary class="cursor-pointer text-sm font-medium">
            Zusatztexte der E-Mails
          </summary>
          <p class="mt-2 text-sm text-ink-muted">
            Erscheint unter dem festen Text der jeweiligen Mail. Variablen:
            {number}, {pickup}, {return}, {responsible}.
          </p>
          <div class="mt-3 grid gap-4">
            <UiTextarea
              v-for="message in mailTexts"
              :key="message.type"
              v-model="form.loans.mail_texts[message.type]"
              :label="message.label"
              :rows="2"
              :error="form.errors[`loans.mail_texts.${message.type}`]"
            />
          </div>
        </details>
      </UiCard>

      <UiCard title="Leihvertrag">
        <template #actions>
          <UiButton
            variant="secondary"
            size="sm"
            icon="file-pdf"
            href="/loans/settings/preview.pdf"
            external
            >Vorschau (PDF)</UiButton
          >
        </template>
        <div class="space-y-4">
          <p class="text-sm text-ink-muted">
            Ein Absatz je Block, getrennt durch eine Leerzeile. „## “ und „### “
            sind Überschriften, „1. “ und „- “ Listen, **fett** ist fett. Jede
            Buchung behält den Text, der bei ihrer Ausgabe galt.
          </p>
          <UiTextarea
            v-model="form.loans.contract_template"
            label="Text"
            :rows="18"
            :error="form.errors['loans.contract_template']"
          />
          <details class="text-sm">
            <summary class="cursor-pointer font-medium">Variablen</summary>
            <ul class="mt-2 grid gap-1 sm:grid-cols-2">
              <li v-for="variable in variables" :key="variable.name">
                <code>{{ "{" + variable.name + "}" }}</code>
                {{ variable.label }}
              </li>
            </ul>
          </details>
          <UiCheckbox
            v-if="!isDefaultText"
            v-model="form.reset_text"
            label="Zurück zum mitgelieferten Text"
            description="Der Entwurf aus Stocks, nicht juristisch geprüft."
          />
        </div>
      </UiCard>

      <UiButton type="submit" :disabled="form.processing">Speichern</UiButton>
    </form>

    <div class="space-y-6">
      <UiCard
        title="Fachschaften"
        description="Kontakte gehören zu einer aktiven Fachschaft oder sind privat."
        flush
      >
        <ul class="divide-y divide-line">
          <li
            v-for="council in councils"
            :key="council.id"
            class="flex items-center justify-between gap-2 px-4 py-2 sm:px-6"
          >
            <span
              :class="council.is_active ? '' : 'text-ink-muted line-through'"
            >
              {{ council.name }}
              <span class="text-xs text-ink-muted"
                >· {{ council.contacts_count }} Kontakte</span
              >
            </span>
            <span class="flex gap-1">
              <UiButton variant="ghost" size="sm" @click="toggle(council)">{{
                council.is_active ? "Ausschalten" : "Einschalten"
              }}</UiButton>
              <UiButton
                v-if="!council.contacts_count"
                variant="ghost"
                size="sm"
                icon="trash"
                :aria-label="`${council.name} löschen`"
                @click="
                  router.delete(`/loans/councils/${council.id}`, {
                    preserveScroll: true,
                  })
                "
              />
            </span>
          </li>
        </ul>
        <form
          class="flex flex-wrap items-end gap-2 border-t border-line p-4 sm:px-6"
          @submit.prevent="addCouncil"
        >
          <UiInput
            v-model="councilForm.number"
            label="Nummer"
            type="number"
            class="w-24"
            :error="councilForm.errors.number"
          />
          <UiInput
            v-model="councilForm.name"
            label="Name"
            class="flex-1"
            :error="councilForm.errors.name"
          />
          <UiButton type="submit" icon="plus">Anlegen</UiButton>
        </form>
      </UiCard>

      <UiCard title="Vorschau mit Beispielwerten">
        <!-- html from the server: escaped text with headings and lists (ContractTemplate::render) -->
        <div
          class="contract max-h-[40rem] space-y-2 overflow-y-auto text-sm"
          v-html="example"
        />
      </UiCard>
    </div>
  </div>
</template>

<script setup lang="ts">
import { router, useForm } from "@inertiajs/vue3";

type Council = {
  id: number;
  number: number;
  name: string;
  is_active: boolean;
  contacts_count: number;
};

/**
 * Settings of the loan module.
 */
const props = defineProps<{
  settings: Record<string, string | number | null>;
  isDefaultText: boolean;
  variables: { name: string; label: string }[];
  example: string;
  councils: Council[];
  // messages with an extra text of their own
  mailTexts: { type: string; label: string }[];
}>();

// nested like the keys on the server ("loans.lender"), so errors land at their field
const form = useForm({
  loans: {
    lender: String(props.settings["loans.lender"] ?? ""),
    place: String(props.settings["loans.place"] ?? ""),
    buffer_hours: Number(props.settings["loans.buffer_hours"] ?? 0),
    number_prefix: String(props.settings["loans.number_prefix"] ?? "B"),
    contract_template: String(props.settings["loans.contract_template"] ?? ""),
    page_days_after_close: Number(
      props.settings["loans.page_days_after_close"] ?? 30,
    ),
    remind_pickup_hours: Number(
      props.settings["loans.remind_pickup_hours"] ?? 24,
    ),
    remind_return_hours: Number(
      props.settings["loans.remind_return_hours"] ?? 24,
    ),
    overdue_days: String(props.settings["loans.overdue_days"] ?? "1, 3, 7"),
    pickup_place: String(props.settings["loans.pickup_place"] ?? ""),
    mail_texts: Object.fromEntries(
      props.mailTexts.map((message) => [
        message.type,
        (
          props.settings["loans.mail_texts"] as unknown as Record<
            string,
            string
          > | null
        )?.[message.type] ?? "",
      ]),
    ) as Record<string, string>,
  },
  reset_text: false,
});
const save = () => form.put("/loans/settings", { preserveScroll: true });

const councilForm = useForm({ number: "", name: "" });
const addCouncil = () =>
  councilForm.post("/loans/councils", {
    preserveScroll: true,
    onSuccess: () => councilForm.reset(),
  });
const toggle = (council: Council) =>
  router.put(
    `/loans/councils/${council.id}`,
    {
      number: council.number,
      name: council.name,
      is_active: !council.is_active,
    },
    { preserveScroll: true },
  );
</script>

<style scoped>
.contract :deep(.nowrap) {
  white-space: nowrap;
}
.contract :deep(h2) {
  font-weight: 700;
  font-size: 1rem;
}
.contract :deep(h3) {
  font-weight: 600;
  margin-top: 0.75rem;
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
