<template>
  <UiPageHeader
    :title="
      labels.length === 1
        ? 'Etikett drucken'
        : `${labels.length} Etiketten drucken`
    "
    description="Vorschau mit den echten Daten. Änderungen hier gelten nur für diesen Druck."
  >
    <UiButton v-if="back" variant="secondary" icon="chevron-left" :href="back">
      Zurück
    </UiButton>
  </UiPageHeader>

  <UiEmptyState
    v-if="!labels.length"
    icon="tag"
    title="Nichts zu drucken"
    description="Wähle Gegenstände in der Liste aus oder öffne „Etikett drucken“ an einem Gegenstand oder Standort."
  />

  <template v-else>
    <!-- the address in the codes can not change for printed labels -->
    <UiAlert v-if="!baseUrlConfirmed" type="warning" class="mb-6">
      Die Codes führen auf <strong class="font-mono">{{ baseUrl }}</strong
      >. Ändert sich diese Adresse später, öffnet die Handykamera die Etiketten
      nicht mehr (der Scan in Stocks liest weiter die Nummer). Die Adresse steht
      unter Verwaltung › Einstellungen › Allgemein.
      <button
        type="button"
        class="ml-1 font-bold underline"
        @click="confirmAddress"
      >
        Adresse stimmt, weiter
      </button>
    </UiAlert>

    <LabelsEditor
      v-if="current"
      ref="editor"
      :key="currentTarget"
      v-model="designs[currentTarget]"
      :data="currentData"
      :variables="variables[currentTarget]"
      :logo-url="logoUrl"
      :printer="printer"
    >
      <template #aside>
        <UiCard title="Drucken">
          <div class="space-y-4">
            <UiSelect
              v-model="printerId"
              label="Drucker"
              :options="
                printers.map((entry) => ({
                  value: entry.id,
                  label: entry.name,
                }))
              "
              :placeholder="null"
            />
            <UiSelect
              v-for="target in targets"
              :key="target"
              :model-value="templateIds[target]"
              :label="`Vorlage ${targetLabels[target]}`"
              :options="templatesOf(target)"
              :placeholder="null"
              @update:model-value="chooseTemplate(target, Number($event))"
            />
            <UiInput
              v-if="current.target === 'item' || current.target === 'box'"
              :model-value="currentData.values.hint"
              label="Hinweis fürs Etikett"
              hint="Gilt nur für diesen Druck; dauerhaft beim Gegenstand unter „Bearbeiten“."
              @update:model-value="hints[current.key] = String($event ?? '')"
            />
            <UiInput
              v-model="copies"
              label="Anzahl je Etikett"
              type="number"
              inputmode="numeric"
            />

            <!-- browse through a batch -->
            <div v-if="labels.length > 1" class="flex items-center gap-2">
              <UiButton
                variant="secondary"
                size="sm"
                icon="arrow-left"
                :disabled="index === 0"
                @click="index--"
              >
                <span class="sr-only">Vorheriges</span>
              </UiButton>
              <span class="flex-1 truncate text-center text-sm">
                {{ index + 1 }} / {{ labels.length }} · {{ current.title }}
              </span>
              <UiButton
                variant="secondary"
                size="sm"
                icon="arrow-right"
                :disabled="index === labels.length - 1"
                @click="index++"
              >
                <span class="sr-only">Nächstes</span>
              </UiButton>
            </div>

            <!-- a batch prints all labels or only the chosen ones ("einzeln") -->
            <div v-if="labels.length > 1" class="space-y-2">
              <div
                class="grid grid-cols-2 gap-1 rounded-md bg-surface-muted p-1 text-sm"
              >
                <button
                  v-for="option in scopeOptions"
                  :key="option.value"
                  type="button"
                  :class="[
                    'rounded px-2 py-1.5 font-medium',
                    scope === option.value
                      ? 'bg-surface text-ink shadow'
                      : 'text-ink-muted hover:text-ink',
                  ]"
                  @click="scope = option.value"
                >
                  {{ option.label }}
                </button>
              </div>
              <ul
                v-if="scope === 'selected'"
                class="max-h-64 divide-y divide-line overflow-y-auto rounded-md ring-1 ring-line"
              >
                <li
                  v-for="(label, position) in labels"
                  :key="label.key"
                  :class="[
                    'flex items-center gap-2 px-2 py-1.5 text-sm',
                    position === index ? 'bg-accent/10' : '',
                  ]"
                >
                  <input
                    v-model="chosen"
                    type="checkbox"
                    :value="label.key"
                    class="h-4 w-4 rounded border-line text-accent focus:ring-accent"
                    :aria-label="`${label.title} drucken`"
                  />
                  <button
                    type="button"
                    class="min-w-0 flex-1 truncate text-left"
                    @click="index = position"
                  >
                    {{ label.title }}
                    <span class="font-mono text-xs text-ink-muted">{{
                      label.code
                    }}</span>
                  </button>
                </li>
              </ul>
              <p v-if="scope === 'selected'" class="text-xs text-ink-muted">
                {{ chosen.length }} von {{ labels.length }} ausgewählt. Antippen
                zeigt das Etikett in der Vorschau.
              </p>
            </div>

            <div class="space-y-2 border-t border-line pt-4">
              <template v-if="printer?.driver === 'niimbot'">
                <template v-if="connections.length">
                  <UiButton
                    v-for="connection in connections"
                    v-show="!connected"
                    :key="connection"
                    variant="secondary"
                    class="w-full"
                    :disabled="busy"
                    @click="connectPrinter(connection)"
                  >
                    {{
                      connection === "bluetooth"
                        ? "Per Bluetooth verbinden"
                        : "Per USB verbinden"
                    }}
                  </UiButton>
                  <p v-if="connected" class="text-sm text-ink-muted">
                    Verbunden{{ deviceName ? ` mit ${deviceName}` : "" }}.
                  </p>
                  <UiButton
                    v-if="connected"
                    class="w-full"
                    icon="print"
                    :disabled="busy || !baseUrlConfirmed"
                    @click="printNiimbot"
                  >
                    Drucken
                  </UiButton>
                </template>
                <p v-else class="text-sm text-ink-muted">
                  Direkt drucken geht nur in Chrome oder Edge am PC und unter
                  Android. Hier das Bild herunterladen und mit der Niimbot-App
                  drucken.
                </p>
                <UiButton
                  :variant="connections.length ? 'secondary' : 'primary'"
                  class="w-full"
                  icon="download"
                  :disabled="busy || !baseUrlConfirmed"
                  @click="downloadPng"
                >
                  {{
                    labels.length > 1
                      ? "Bilder herunterladen (ZIP)"
                      : "Als Bild herunterladen"
                  }}
                </UiButton>
              </template>
              <UiButton
                v-else
                class="w-full"
                icon="print"
                :disabled="busy || !baseUrlConfirmed"
                @click="printOnPaper"
              >
                Drucken
              </UiButton>

              <!-- progress of a batch on the niimbot -->
              <div v-if="progress" class="space-y-2">
                <div class="h-2 overflow-hidden rounded-full bg-surface-muted">
                  <div
                    class="h-full bg-accent transition-all"
                    :style="{
                      width: `${(progress.done / progress.total) * 100}%`,
                    }"
                  />
                </div>
                <p
                  class="flex items-center justify-between text-sm text-ink-muted"
                >
                  {{ progress.done }} von {{ progress.total }} gedruckt
                  <button
                    type="button"
                    class="font-medium underline"
                    @click="cancel"
                  >
                    Abbrechen
                  </button>
                </p>
              </div>
              <p v-if="printError" class="text-sm text-red-600">
                {{ printError }}
              </p>
              <p
                v-if="printed"
                class="text-sm text-green-700 dark:text-green-400"
              >
                {{ printed }}
              </p>
            </div>

            <!-- templates are edited on the pc, the kiosk only prints -->
            <div
              v-if="can('labels.manage') && !kiosk"
              class="border-t border-line pt-4"
            >
              <UiButton
                variant="ghost"
                size="sm"
                icon="check"
                class="w-full"
                @click="saveTemplate"
              >
                Als Vorlage speichern
              </UiButton>
            </div>
          </div>
        </UiCard>
      </template>
    </LabelsEditor>
  </template>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import { loadImages } from "../../labels/render";
import {
  downloadImages,
  printPaper,
  renderForPrinter,
  type PrintLabel,
} from "../../labels/drivers";
import * as niimbot from "../../labels/drivers/niimbot";

/**
 * Print preview and printing of one label or a batch. Every target (item, box, location, stock)
 * prints with its own template; changes in the preview apply to the whole batch, "Als Vorlage
 * speichern" keeps them.
 */
const props = defineProps<{
  labels: LabelData[];
  templates: LabelTemplate[];
  printers: PrinterData[];
  variables: Record<LabelTargetName, string[]>;
  baseUrl: string;
  baseUrlConfirmed: boolean;
  logoUrl: string | null;
  back: string | null;
}>();

const { can } = usePermissions();
const { surface, kiosk, url } = useSurface();

const targetLabels: Record<LabelTargetName, string> = {
  item: "Gegenstand",
  box: "Box",
  location: "Standort",
  reserve: "Vorrat",
};

// printer: the last one used in this browser (a bluetooth printer belongs to a device)
const readPrinterId = () => {
  try {
    return Number(localStorage.getItem("labelPrinter")) || null;
  } catch {
    return null;
  }
};
const printerId = ref(
  props.printers.find((entry) => entry.id === readPrinterId())?.id ??
    props.printers[0]?.id ??
    null,
);
const printer = computed(
  () => props.printers.find((entry) => entry.id === printerId.value) ?? null,
);
watch(printerId, (id) => {
  try {
    localStorage.setItem("labelPrinter", String(id));
  } catch {
    // private mode: not remembered
  }
});

// templates per target, preselected is the default of the target
const targets = computed(() => [
  ...new Set(props.labels.map((label) => label.target)),
]);
const templatesOf = (target: LabelTargetName) =>
  props.templates
    .filter((template) => template.target === target)
    .map((template) => ({ value: template.id, label: template.name }));

const templateIds = reactive<Partial<Record<LabelTargetName, number>>>({});
const designs = reactive<
  Record<
    string,
    { width_mm: number; height_mm: number; elements: LabelElement[] }
  >
>({});
const chooseTemplate = (target: LabelTargetName, id: number) => {
  const template = props.templates.find((entry) => entry.id === id);
  if (!template) {
    return;
  }
  templateIds[target] = id;
  designs[target] = {
    width_mm: template.width_mm,
    height_mm: template.height_mm,
    elements: JSON.parse(JSON.stringify(template.elements)),
  };
};
for (const target of targets.value) {
  const candidates = props.templates.filter(
    (template) => template.target === target,
  );
  const preferred =
    candidates.find((template) => template.is_default) ?? candidates[0];
  if (preferred) {
    chooseTemplate(target, preferred.id);
  }
}

// the label shown in the preview, with the hint changed for this print
const index = ref(0);
const hints = reactive<Record<string, string>>({});
const withHint = (label: LabelData): LabelData =>
  label.key in hints
    ? { ...label, values: { ...label.values, hint: hints[label.key] } }
    : label;
const current = computed(() => props.labels[index.value] ?? null);
const currentTarget = computed(() => current.value?.target ?? "item");
const currentData = computed(() => withHint(current.value));

const copies = ref<number | string>(1);
const copyCount = () => Math.max(1, Math.min(100, Number(copies.value) || 1));

// the address must be confirmed once before printing
const confirmAddress = () =>
  router.post(
    url("/labels/confirm-address"),
    {},
    { preserveScroll: true, preserveState: true },
  );

// drawing all labels for the chosen printer
// all labels, or only the chosen ones of a batch
const scope = ref<"all" | "selected">("all");
const chosen = ref<string[]>([]);
const scopeOptions = computed(() => [
  { value: "all" as const, label: `Alle drucken (${props.labels.length})` },
  { value: "selected" as const, label: `Einzeln (${chosen.value.length})` },
]);
const toPrint = () =>
  props.labels.length > 1 && scope.value === "selected"
    ? props.labels.filter((label) => chosen.value.includes(label.key))
    : props.labels;

const printLabels = (): (PrintLabel & { name: string })[] =>
  toPrint()
    .filter((label) => designs[label.target])
    .map((label) => ({
      template: designs[label.target],
      data: withHint(label),
      name: label.code,
    }));

const busy = ref(false);
const printError = ref<string | null>(null);
const printed = ref<string | null>(null);

const render = async (crop: boolean) => {
  const labels = printLabels();
  const images = await loadImages(
    Object.values(designs),
    labels.map((label) => label.data),
    props.logoUrl,
  );
  return labels.map((label) => ({
    ...renderForPrinter(label, printer.value!, images, props.logoUrl, crop),
    name: label.name,
    width_mm: label.template.width_mm,
    height_mm: label.template.height_mm,
  }));
};

// remember when the labels were printed (filter "noch ohne Etikett")
const markPrinted = () => {
  const ids = (targets: LabelTargetName[]) =>
    toPrint()
      .filter((label) => targets.includes(label.target))
      .map((label) => label.id);
  router.post(
    url("/labels/printed"),
    { items: ids(["item", "box"]), locations: ids(["location"]) },
    { preserveScroll: true, preserveState: true, only: ["labels"] },
  );
};

const run = async (job: () => Promise<string>) => {
  if (!toPrint().length) {
    printError.value = "Wähle mindestens ein Etikett aus.";
    return;
  }
  busy.value = true;
  printError.value = null;
  printed.value = null;
  try {
    printed.value = await job();
    markPrinted();
  } catch (error) {
    printError.value = error instanceof Error ? error.message : String(error);
  } finally {
    busy.value = false;
  }
};

const printOnPaper = () =>
  run(async () => {
    await printPaper(await render(false), copyCount(), printer.value!);
    return "An den Druckdialog übergeben.";
  });

const downloadPng = () =>
  run(async () => {
    await downloadImages(await render(false));
    return "Heruntergeladen. In der Niimbot-App das Bild öffnen und drucken.";
  });

// niimbot directly by bluetooth or usb
// the platform of a kiosk device can rule it out (iPad: Safari has neither bluetooth nor usb)
const connections =
  surface.value?.direct_print === false ? [] : niimbot.directConnections();
const connected = ref(niimbot.isConnected());
const deviceName = ref<string | null>(null);
const connectPrinter = async (connection: niimbot.Connection) => {
  busy.value = true;
  printError.value = null;
  try {
    deviceName.value = await niimbot.connect(connection);
    connected.value = true;
  } catch (error) {
    printError.value = `Verbinden ging nicht: ${error instanceof Error ? error.message : error}`;
  } finally {
    busy.value = false;
  }
};

const progress = ref<{ done: number; total: number } | null>(null);
let abort: AbortController | null = null;
const printNiimbot = () =>
  run(async () => {
    const canvases = await render(true);
    abort = new AbortController();
    progress.value = { done: 0, total: canvases.length };
    try {
      await niimbot.printDirect(
        canvases.map((entry) => entry.canvas),
        copyCount(),
        printer.value!,
        (done, total) => (progress.value = { done, total }),
        abort.signal,
      );
      return abort.signal.aborted
        ? `Abgebrochen nach ${progress.value.done} Etiketten.`
        : "Gedruckt.";
    } finally {
      progress.value = null;
      connected.value = niimbot.isConnected();
    }
  });
const cancel = () => abort?.abort();

// keep the changes of the preview in the template
const saveTemplate = () => {
  const target = currentTarget.value;
  const template = props.templates.find(
    (entry) => entry.id === templateIds[target],
  )!;
  router.put(
    `/labels/templates/${template.id}`,
    {
      name: template.name,
      target: template.target,
      is_default: template.is_default,
      ...designs[target],
    },
    { preserveScroll: true, preserveState: true },
  );
};
</script>
