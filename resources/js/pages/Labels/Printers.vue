<template>
  <UiPageHeader
    title="Drucker"
    description="Drucker für Etiketten. Normale Drucker drucken über den Druckdialog des Browsers, Niimbot-Drucker direkt per Bluetooth oder USB (Chrome, Edge) oder als Bild für die Niimbot-App."
  >
    <UiButton icon="plus" @click="openPrinter(null)">Drucker anlegen</UiButton>
  </UiPageHeader>

  <UiAlert v-if="page.props.errors?.model" type="error" class="mb-4">
    {{ page.props.errors.model }}
  </UiAlert>

  <div class="space-y-6">
    <UiCard title="Drucker" flush>
      <ul v-if="printers.length" class="divide-y divide-line">
        <li
          v-for="printer in printers"
          :key="printer.id"
          class="flex flex-wrap items-center gap-2 px-4 py-3 sm:px-6"
        >
          <span class="font-medium">{{ printer.name }}</span>
          <UiBadge>{{ printer.driver_label }}</UiBadge>
          <span class="text-sm text-ink-muted">{{ summary(printer) }}</span>
          <UiButton
            variant="ghost"
            size="sm"
            icon="pen"
            class="ml-auto"
            @click="openPrinter(printer)"
          >
            <span class="sr-only">Bearbeiten</span>
          </UiButton>
        </li>
      </ul>
      <UiEmptyState v-else icon="print" title="Noch kein Drucker" />
    </UiCard>

    <UiCard
      title="Niimbot-Modelle"
      description="Neue Modelle brauchen kein Programmieren: Auflösung, Druckbreite, Drucktyp von NiimBlueLib und die Etikettengrößen."
      flush
    >
      <template #actions>
        <UiButton
          variant="secondary"
          size="sm"
          icon="plus"
          @click="openModel(null)"
          >Modell</UiButton
        >
      </template>
      <ul class="divide-y divide-line">
        <li
          v-for="model in models"
          :key="model.id"
          class="flex flex-wrap items-center gap-2 px-4 py-3 sm:px-6"
        >
          <span class="font-medium">{{ model.name }}</span>
          <span class="text-sm text-ink-muted">
            {{ model.dpi }} dpi · bis {{ model.max_width_mm }} mm ·
            {{
              model.label_sizes
                .map((size) => `${size.width} × ${size.height}`)
                .join(", ")
            }}
            mm
          </span>
          <UiButton
            variant="ghost"
            size="sm"
            icon="pen"
            class="ml-auto"
            @click="openModel(model)"
          >
            <span class="sr-only">Bearbeiten</span>
          </UiButton>
        </li>
      </ul>
    </UiCard>
  </div>

  <!-- printer -->
  <UiModal
    v-if="editingPrinter"
    :title="editingPrinter.id ? 'Drucker bearbeiten' : 'Drucker anlegen'"
    size="lg"
    @close="editingPrinter = null"
  >
    <form class="space-y-5" @submit.prevent="savePrinter">
      <UiInput
        v-model="printerForm.name"
        label="Name"
        required
        :error="printerForm.errors.name"
      />
      <UiSelect
        v-model="printerForm.driver"
        label="Treiber"
        :options="drivers"
        :placeholder="null"
      />

      <template v-if="printerForm.driver === 'niimbot'">
        <UiSelect
          v-model="printerForm.printer_model_id"
          label="Modell"
          :options="
            models.map((model) => ({ value: model.id, label: model.name }))
          "
          placeholder="Bitte wählen"
        />
        <p
          v-if="printerForm.errors.printer_model_id"
          class="text-sm text-red-600"
        >
          {{ printerForm.errors.printer_model_id }}
        </p>
        <div class="grid grid-cols-2 gap-3">
          <UiInput
            v-model="printerForm.settings.density"
            label="Druckdichte (1–5)"
            type="number"
          />
          <UiSelect
            v-model="printerForm.settings.rotation"
            label="Drehung"
            :options="
              [0, 90, 180, 270].map((value) => ({ value, label: `${value}°` }))
            "
            :placeholder="null"
          />
          <UiInput
            v-model="printerForm.settings.offset_x"
            label="Versatz waagerecht (mm)"
            type="number"
            step="0.1"
          />
          <UiInput
            v-model="printerForm.settings.offset_y"
            label="Versatz senkrecht (mm)"
            type="number"
            step="0.1"
          />
        </div>
      </template>

      <template v-else>
        <UiSelect
          v-model="printerForm.settings.page"
          label="Seitenformat"
          :options="[
            {
              value: 'single',
              label: 'Ein Etikett je Seite (Etikettendrucker)',
            },
            { value: 'sheet', label: 'A4-Bogen' },
          ]"
          :placeholder="null"
        />
        <div
          v-if="printerForm.settings.page === 'sheet'"
          class="grid grid-cols-2 gap-3"
        >
          <UiInput
            v-model="printerForm.settings.columns"
            label="Spalten"
            type="number"
          />
          <UiInput
            v-model="printerForm.settings.rows"
            label="Zeilen"
            type="number"
          />
          <UiInput
            v-model="printerForm.settings.margin_top"
            label="Rand oben (mm)"
            type="number"
            step="0.5"
          />
          <UiInput
            v-model="printerForm.settings.margin_left"
            label="Rand links (mm)"
            type="number"
            step="0.5"
          />
          <UiInput
            v-model="printerForm.settings.gap_x"
            label="Abstand waagerecht (mm)"
            type="number"
            step="0.5"
          />
          <UiInput
            v-model="printerForm.settings.gap_y"
            label="Abstand senkrecht (mm)"
            type="number"
            step="0.5"
          />
        </div>
      </template>

      <p v-if="settingsError" class="text-sm text-red-600">
        {{ settingsError }}
      </p>

      <div class="flex flex-col gap-3 sm:flex-row">
        <UiButton
          type="submit"
          class="flex-1"
          :disabled="printerForm.processing"
          >Speichern</UiButton
        >
        <UiButton
          v-if="editingPrinter.id"
          variant="danger"
          icon="trash"
          @click="destroyPrinter"
        >
          Löschen
        </UiButton>
      </div>
    </form>
  </UiModal>

  <!-- printer model -->
  <UiModal
    v-if="editingModel"
    :title="editingModel.id ? 'Modell bearbeiten' : 'Modell anlegen'"
    size="lg"
    @close="editingModel = null"
  >
    <form class="space-y-5" @submit.prevent="saveModel">
      <div class="grid grid-cols-2 gap-3">
        <UiInput
          v-model="modelForm.name"
          label="Name"
          required
          :error="modelForm.errors.name"
        />
        <UiInput
          v-model="modelForm.print_task"
          label="Drucktyp (NiimBlueLib)"
          required
          :error="modelForm.errors.print_task"
          hint="z. B. B1, D110, B21V1"
        />
        <UiInput
          v-model="modelForm.dpi"
          label="Auflösung (dpi)"
          type="number"
          :error="modelForm.errors.dpi"
        />
        <UiInput
          v-model="modelForm.max_width_mm"
          label="Druckbreite (mm)"
          type="number"
          step="0.5"
          :error="modelForm.errors.max_width_mm"
        />
      </div>
      <fieldset class="space-y-2">
        <legend class="text-sm font-medium">
          Etikettengrößen (Breite × Höhe in mm)
        </legend>
        <div
          v-for="(size, position) in modelForm.label_sizes"
          :key="position"
          class="flex items-end gap-2"
        >
          <UiInput
            v-model="size.width"
            label="Breite"
            type="number"
            step="0.5"
            class="flex-1"
          />
          <UiInput
            v-model="size.height"
            label="Höhe"
            type="number"
            step="0.5"
            class="flex-1"
          />
          <UiButton
            variant="ghost"
            icon="trash"
            :disabled="modelForm.label_sizes.length === 1"
            @click="modelForm.label_sizes.splice(position, 1)"
          >
            <span class="sr-only">Größe entfernen</span>
          </UiButton>
        </div>
        <UiButton
          variant="secondary"
          size="sm"
          icon="plus"
          @click="modelForm.label_sizes.push({ width: 50, height: 30 })"
        >
          Größe
        </UiButton>
        <p v-if="sizesError" class="text-sm text-red-600">{{ sizesError }}</p>
      </fieldset>
      <div class="flex flex-col gap-3 sm:flex-row">
        <UiButton type="submit" class="flex-1" :disabled="modelForm.processing"
          >Speichern</UiButton
        >
        <UiButton
          v-if="editingModel.id"
          variant="danger"
          icon="trash"
          @click="destroyModel"
          >Löschen</UiButton
        >
      </div>
    </form>
  </UiModal>
</template>

<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { router, useForm, usePage } from "@inertiajs/vue3";

/**
 * Printers with their driver settings, and the list of niimbot models.
 */
const props = defineProps<{
  printers: PrinterData[];
  models: (PrinterModelData & { printers_count: number })[];
  drivers: Option[];
  defaults: Record<"paper" | "niimbot", PrinterData["settings"]>;
}>();

const page = usePage<SharedProps>();

const summary = (printer: PrinterData) =>
  printer.driver === "niimbot"
    ? `${printer.model?.name ?? "ohne Modell"} · Dichte ${printer.settings.density}`
    : printer.settings.page === "sheet"
      ? `A4-Bogen ${printer.settings.columns} × ${printer.settings.rows}`
      : "ein Etikett je Seite";

// printer
const editingPrinter = ref<{ id: number | null } | null>(null);
const printerForm = useForm({
  name: "",
  driver: "niimbot" as "paper" | "niimbot",
  printer_model_id: null as number | null,
  settings: {} as PrinterData["settings"],
});
const settingsError = computed(
  () =>
    Object.entries(printerForm.errors).find(([key]) =>
      key.startsWith("settings"),
    )?.[1] ?? null,
);
// a new driver starts with its default settings
watch(
  () => printerForm.driver,
  (driver) => {
    printerForm.settings = {
      ...props.defaults[driver],
      ...pick(printerForm.settings, driver),
    };
  },
);
const pick = (settings: PrinterData["settings"], driver: "paper" | "niimbot") =>
  Object.fromEntries(
    Object.entries(settings).filter(([key]) => key in props.defaults[driver]),
  );

const openPrinter = (printer: PrinterData | null) => {
  const driver = printer?.driver ?? "niimbot";
  printerForm.defaults({
    name: printer?.name ?? "",
    driver,
    printer_model_id: printer?.printer_model_id ?? props.models[0]?.id ?? null,
    settings: { ...props.defaults[driver], ...(printer?.settings ?? {}) },
  });
  printerForm.reset();
  printerForm.clearErrors();
  editingPrinter.value = { id: printer?.id ?? null };
};
const savePrinter = () => {
  const options = {
    preserveScroll: true,
    onSuccess: () => (editingPrinter.value = null),
  };
  if (editingPrinter.value?.id) {
    printerForm.put(`/labels/printers/${editingPrinter.value.id}`, options);
  } else {
    printerForm.post("/labels/printers", options);
  }
};
const destroyPrinter = () =>
  router.delete(`/labels/printers/${editingPrinter.value!.id}`, {
    preserveScroll: true,
    onFinish: () => (editingPrinter.value = null),
  });

// model
const editingModel = ref<{ id: number | null } | null>(null);
const modelForm = useForm({
  name: "",
  dpi: 203 as number | string,
  max_width_mm: 48 as number | string,
  print_task: "",
  label_sizes: [{ width: 50, height: 30 }] as {
    width: number | string;
    height: number | string;
  }[],
});
const sizesError = computed(
  () =>
    Object.entries(modelForm.errors).find(([key]) =>
      key.startsWith("label_sizes"),
    )?.[1] ?? null,
);
const openModel = (model: PrinterModelData | null) => {
  modelForm.defaults({
    name: model?.name ?? "",
    dpi: model?.dpi ?? 203,
    max_width_mm: model?.max_width_mm ?? 48,
    print_task: model?.print_task ?? "",
    label_sizes: JSON.parse(
      JSON.stringify(model?.label_sizes ?? [{ width: 50, height: 30 }]),
    ),
  });
  modelForm.reset();
  modelForm.clearErrors();
  editingModel.value = { id: model?.id ?? null };
};
const saveModel = () => {
  const options = {
    preserveScroll: true,
    onSuccess: () => (editingModel.value = null),
  };
  if (editingModel.value?.id) {
    modelForm.put(`/labels/printer-models/${editingModel.value.id}`, options);
  } else {
    modelForm.post("/labels/printer-models", options);
  }
};
const destroyModel = () =>
  router.delete(`/labels/printer-models/${editingModel.value!.id}`, {
    preserveScroll: true,
    onFinish: () => (editingModel.value = null),
  });
</script>
