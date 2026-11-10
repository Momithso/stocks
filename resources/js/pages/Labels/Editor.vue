<template>
  <UiPageHeader
    :title="form.name || 'Vorlage'"
    :description="`Etikettenvorlage · ${targetLabel}`"
  >
    <UiButton variant="secondary" icon="chevron-left" href="/labels/templates">
      Alle Vorlagen
    </UiButton>
    <UiButton icon="check" :disabled="form.processing" @click="save"
      >Speichern</UiButton
    >
  </UiPageHeader>

  <UiAlert v-if="errorList.length" type="error" class="mb-4">
    {{ errorList.join(" ") }}
  </UiAlert>

  <LabelsEditor
    v-model="design"
    :data="samples[form.target]"
    :variables="variables[form.target]"
    :logo-url="logoUrl"
    :printer="printer"
    can-upload
  >
    <template #aside>
      <UiCard title="Vorlage">
        <div class="space-y-4">
          <UiInput
            v-model="form.name"
            label="Name"
            required
            :error="form.errors.name"
          />
          <UiSelect
            v-model="form.target"
            label="Art"
            :options="targets"
            :placeholder="null"
            hint="Die Art bestimmt, welche Variablen es gibt."
          />
          <UiSelect
            v-if="sizes.length"
            v-model="sizeChoice"
            label="Etikettengröße"
            :options="sizeOptions"
            placeholder="Eigene Größe"
          />
          <div class="grid grid-cols-2 gap-3">
            <UiInput
              :model-value="design.width_mm"
              label="Breite (mm)"
              type="number"
              step="0.5"
              :error="form.errors.width_mm"
              @update:model-value="
                design.width_mm = Number($event) || design.width_mm
              "
            />
            <UiInput
              :model-value="design.height_mm"
              label="Höhe (mm)"
              type="number"
              step="0.5"
              :error="form.errors.height_mm"
              @update:model-value="
                design.height_mm = Number($event) || design.height_mm
              "
            />
          </div>
          <UiCheckbox
            v-model="form.is_default"
            label="Standard"
            :description="`Beim Drucken für „${targetLabel}“ vorausgewählt.`"
          />
          <UiSelect
            v-model="printerId"
            label="Vorschau für"
            :options="printerOptions"
            placeholder="Bildschirm"
          />
          <p class="text-sm text-ink-muted">
            Beispiel: {{ samples[form.target].title }}. Ein Element auswählen,
            um es zu bearbeiten.
          </p>
        </div>
      </UiCard>
    </template>
  </LabelsEditor>
</template>

<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useForm, usePage } from "@inertiajs/vue3";

/**
 * Editor of a label template. A sample of the inventory fills the variables, so long names show
 * whether they fit.
 */
const props = defineProps<{
  template: LabelTemplate;
  targets: { value: LabelTargetName; label: string }[];
  variables: Record<LabelTargetName, string[]>;
  sizes: { label: string; width: number; height: number }[];
  samples: Record<LabelTargetName, LabelData>;
  printers: PrinterData[];
  logoUrl: string | null;
}>();

const page = usePage<SharedProps>();

const design = ref({
  width_mm: props.template.width_mm,
  height_mm: props.template.height_mm,
  elements: JSON.parse(JSON.stringify(props.template.elements)),
});
const form = useForm({
  name: props.template.name,
  target: props.template.target,
  is_default: props.template.is_default,
});

const targetLabel = computed(
  () =>
    props.targets.find((target) => target.value === form.target)?.label ?? "",
);

// sizes of the printers
const sizeOptions = computed(() =>
  props.sizes.map((entry, index) => ({ value: index, label: entry.label })),
);
const sizeChoice = ref<number | null>(
  props.sizes.findIndex(
    (entry) =>
      entry.width === design.value.width_mm &&
      entry.height === design.value.height_mm,
  ),
);
if (sizeChoice.value === -1) {
  sizeChoice.value = null;
}
watch(sizeChoice, (index) => {
  if (index !== null && props.sizes[index]) {
    design.value.width_mm = props.sizes[index].width;
    design.value.height_mm = props.sizes[index].height;
  }
});

// preview in black and white of a niimbot printer
const printerOptions = computed(() =>
  props.printers.map((printer) => ({ value: printer.id, label: printer.name })),
);
const printerId = ref<number | null>(null);
const printer = computed(
  () => props.printers.find((entry) => entry.id === printerId.value) ?? null,
);

const errorList = computed(() =>
  Object.entries(page.props.errors ?? {})
    .filter(([key]) => key.startsWith("elements"))
    .map(([, message]) => message),
);

const save = () =>
  form
    .transform((data) => ({ ...data, ...design.value }))
    .put(`/labels/templates/${props.template.id}`, {
      preserveScroll: true,
      preserveState: true,
    });
</script>
