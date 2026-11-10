<template>
  <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
    <div class="min-w-0 space-y-4">
      <!-- add elements -->
      <div class="flex flex-wrap items-center gap-2">
        <UiButton
          v-for="tool in tools"
          :key="tool.type"
          variant="secondary"
          size="sm"
          :icon="tool.icon"
          @click="add(tool.type)"
        >
          {{ tool.label }}
        </UiButton>
        <span class="ml-auto flex items-center gap-1">
          <button
            type="button"
            class="rounded-md p-2 text-ink-muted hover:bg-surface-muted hover:text-ink"
            aria-label="Verkleinern"
            @click="zoomBy(1 / 1.25)"
          >
            <FontAwesomeIcon :icon="['fas', 'magnifying-glass-minus']" />
          </button>
          <span class="w-12 text-center text-xs text-ink-muted">
            {{ Math.round(((zoom * 25.4) / 96) * 100) }} %
          </span>
          <button
            type="button"
            class="rounded-md p-2 text-ink-muted hover:bg-surface-muted hover:text-ink"
            aria-label="Vergrößern"
            @click="zoomBy(1.25)"
          >
            <FontAwesomeIcon :icon="['fas', 'magnifying-glass-plus']" />
          </button>
        </span>
      </div>

      <!-- the label, enlarged; the canvas shows exactly what is printed -->
      <div
        ref="stageBox"
        class="overflow-auto rounded-lg bg-surface-muted p-6"
        @pointerdown.self="selectedId = null"
      >
        <div
          class="relative mx-auto shadow-md"
          :style="{
            width: `${template.width_mm * zoom}px`,
            height: `${template.height_mm * zoom}px`,
          }"
          data-label-stage
          @pointerdown.self="selectedId = null"
        >
          <canvas
            ref="canvas"
            class="pointer-events-none absolute inset-0 h-full w-full bg-white"
            :style="{ imageRendering: monochrome ? 'pixelated' : 'auto' }"
          />
          <!-- not printed area of a narrower print head -->
          <template v-if="unprintable > 0">
            <div
              class="pointer-events-none absolute inset-y-0 left-0 bg-[repeating-linear-gradient(45deg,rgba(239,68,68,.18)_0_4px,transparent_4px_8px)]"
              :style="{ width: `${unprintable * zoom}px` }"
            />
            <div
              class="pointer-events-none absolute inset-y-0 right-0 bg-[repeating-linear-gradient(45deg,rgba(239,68,68,.18)_0_4px,transparent_4px_8px)]"
              :style="{ width: `${unprintable * zoom}px` }"
            />
          </template>
          <div
            v-for="element in template.elements"
            :key="element.id"
            :data-element="element.id"
            :class="[
              'absolute cursor-move touch-none select-none',
              element.id === selectedId
                ? 'outline outline-2 outline-accent'
                : 'outline-dashed outline-1 outline-transparent hover:outline-ink-muted',
            ]"
            :style="{
              left: `${element.x * zoom}px`,
              top: `${element.y * zoom}px`,
              width: `${element.w * zoom}px`,
              height: `${element.h * zoom}px`,
            }"
            @pointerdown.stop="startDrag($event, element, 'move')"
          >
            <template v-if="element.id === selectedId">
              <span
                v-for="corner in corners"
                :key="corner"
                :data-handle="corner"
                :class="[
                  'absolute h-3 w-3 rounded-sm border-2 border-accent bg-white',
                  corner.includes('n') ? '-top-1.5' : '-bottom-1.5',
                  corner.includes('w') ? '-left-1.5' : '-right-1.5',
                  corner === 'nw' || corner === 'se'
                    ? 'cursor-nwse-resize'
                    : 'cursor-nesw-resize',
                ]"
                @pointerdown.stop="startDrag($event, element, corner)"
              />
            </template>
          </div>
        </div>
      </div>

      <p class="text-xs text-ink-muted">
        {{ template.width_mm }} × {{ template.height_mm }} mm. Ziehen zum
        Verschieben, an den Ecken für die Größe; Pfeiltasten rücken um 1 mm, mit
        Umschalt um 0,1 mm.
        <template v-if="monochrome">
          Vorschau in Schwarz-Weiß, wie der Thermodrucker druckt.
        </template>
      </p>

      <UiAlert v-if="warnings.length" type="warning">
        <ul class="list-inside list-disc space-y-1">
          <li v-for="warning in warnings" :key="warning">{{ warning }}</li>
        </ul>
      </UiAlert>
    </div>

    <!-- properties of the selected element -->
    <aside class="space-y-4">
      <UiCard v-if="selected" :title="elementName(selected)">
        <div class="space-y-4">
          <div class="grid grid-cols-2 gap-3">
            <UiInput
              v-for="field in ['x', 'y', 'w', 'h'] as const"
              :key="field"
              :model-value="selected[field]"
              :label="geometryLabels[field]"
              type="number"
              step="0.5"
              @update:model-value="setNumber(field, $event)"
            />
          </div>

          <template v-if="selected.type === 'text'">
            <UiTextarea
              v-model="selected.text"
              label="Text"
              :rows="3"
              hint="Variablen in geschweiften Klammern werden beim Drucken ersetzt."
            />
            <div class="flex flex-wrap gap-1">
              <button
                v-for="variable in variables"
                :key="variable"
                type="button"
                class="rounded bg-surface-muted px-1.5 py-0.5 font-mono text-xs hover:bg-accent/10 hover:text-accent"
                @click="insertVariable(variable)"
              >
                {{ "{" + variable + "}" }}
              </button>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <UiInput
                :model-value="selected.font_size ?? 8"
                label="Schriftgröße (pt)"
                type="number"
                step="0.5"
                @update:model-value="setNumber('font_size', $event)"
              />
              <UiSelect
                v-model="selected.overflow"
                label="Zu lang"
                :options="overflowOptions"
                :placeholder="null"
              />
            </div>
            <div class="flex flex-wrap gap-1">
              <button
                type="button"
                :class="toggleClass(!!selected.bold)"
                aria-label="Fett"
                @click="selected.bold = !selected.bold"
              >
                <FontAwesomeIcon :icon="['fas', 'bold']" />
              </button>
              <button
                v-for="align in ['left', 'center', 'right'] as const"
                :key="align"
                type="button"
                :class="toggleClass((selected.align ?? 'left') === align)"
                :aria-label="alignLabels[align]"
                @click="selected.align = align"
              >
                <FontAwesomeIcon :icon="['fas', `align-${align}`]" />
              </button>
              <UiSelect
                v-model="selected.valign"
                class="ml-auto"
                aria-label="Senkrecht"
                :options="valignOptions"
                :placeholder="null"
              />
            </div>
          </template>

          <UiSelect
            v-if="selected.type === 'code'"
            v-model="selected.symbology"
            label="Code-Art"
            :options="symbologyOptions"
            :placeholder="null"
            hint="QR-Code liest jede Handykamera; Data Matrix ist kleiner, braucht aber einen Scanner oder den Scan in Stocks."
          />

          <div v-if="selected.type === 'image'" class="space-y-2">
            <label class="block text-sm font-medium">
              Bild
              <input
                type="file"
                accept="image/png,image/jpeg,image/webp"
                class="mt-1 block w-full text-sm font-normal text-ink-muted file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-surface-muted file:px-3 file:py-2 file:text-sm file:font-medium file:text-ink"
                :disabled="uploading"
                @change="uploadImage"
              />
            </label>
            <p v-if="uploadError" class="text-sm text-red-600">
              {{ uploadError }}
            </p>
          </div>

          <p v-if="selected.type === 'photo'" class="text-sm text-ink-muted">
            Zeigt das erste Foto des Gegenstands. Auf dem Thermodrucker nur grob
            erkennbar.
          </p>

          <UiInput
            v-if="selected.type === 'line' || selected.type === 'rect'"
            :model-value="selected.stroke ?? 0.3"
            label="Strichstärke (mm)"
            type="number"
            step="0.1"
            @update:model-value="setNumber('stroke', $event)"
          />

          <div class="flex flex-wrap gap-2 border-t border-line pt-4">
            <UiButton
              variant="secondary"
              size="sm"
              icon="rotate-right"
              @click="rotate"
            >
              Drehen
            </UiButton>
            <UiButton
              variant="secondary"
              size="sm"
              icon="copy"
              @click="duplicate"
            >
              Kopie
            </UiButton>
            <UiButton variant="ghost" size="sm" icon="trash" @click="remove">
              Entfernen
            </UiButton>
          </div>
        </div>
      </UiCard>
      <slot v-else name="aside" />
    </aside>
  </div>
</template>

<script setup lang="ts">
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
} from "vue";
import {
  drawLabel,
  elementName,
  loadImages,
  templateWarnings,
  toMonochrome,
} from "../../labels/render";

/**
 * Editor of a label: elements on the label enlarged, moved with mouse or finger, sized at the
 * corners, nudged with the arrow keys; properties of the selected element on the right. Used for
 * templates and for the print preview (changes only for this print).
 */
const template = defineModel<{
  width_mm: number;
  height_mm: number;
  elements: LabelElement[];
}>({ required: true });

const props = withDefaults(
  defineProps<{
    // real data or a sample that fills the variables
    data: LabelData;
    variables: string[];
    logoUrl: string | null;
    // the printer decides black and white and the print width
    printer?: PrinterData | null;
    // free images can only be uploaded with labels.manage
    canUpload?: boolean;
  }>(),
  { printer: null, canUpload: false },
);

const tools = computed(
  () =>
    [
      { type: "text", label: "Text", icon: "font" },
      { type: "code", label: "Code", icon: "qrcode" },
      { type: "logo", label: "Logo", icon: "star" },
      { type: "image", label: "Bild", icon: "image" },
      { type: "photo", label: "Foto", icon: "file-image" },
      { type: "line", label: "Linie", icon: "minus" },
      { type: "rect", label: "Rahmen", icon: "square" },
    ].filter((tool) => tool.type !== "image" || props.canUpload) as {
      type: LabelElement["type"];
      label: string;
      icon: string;
    }[],
);
const corners = ["nw", "ne", "sw", "se"] as const;
const geometryLabels = {
  x: "Links (mm)",
  y: "Oben (mm)",
  w: "Breite (mm)",
  h: "Höhe (mm)",
};
const alignLabels = { left: "Links", center: "Mitte", right: "Rechts" };
const overflowOptions = [
  { value: "shrink", label: "verkleinern" },
  { value: "clip", label: "abschneiden" },
];
const valignOptions = [
  { value: "top", label: "oben" },
  { value: "middle", label: "mittig" },
  { value: "bottom", label: "unten" },
];
const symbologyOptions = [
  { value: "qrcode", label: "QR-Code" },
  { value: "datamatrix", label: "Data Matrix" },
];

const toggleClass = (active: boolean) => [
  "flex h-9 w-9 items-center justify-center rounded-md",
  active
    ? "bg-accent text-white"
    : "bg-surface-muted text-ink-muted hover:text-ink",
];

// selection
const selectedId = ref<string | null>(null);
const selected = computed(
  () =>
    template.value.elements.find(
      (element) => element.id === selectedId.value,
    ) ?? null,
);

const round = (value: number) => Math.round(value * 10) / 10;

const setNumber = (
  field: "x" | "y" | "w" | "h" | "font_size" | "stroke",
  value: unknown,
) => {
  const number = Number(String(value).replace(",", "."));
  if (selected.value && Number.isFinite(number)) {
    selected.value[field] = ["w", "h", "font_size", "stroke"].includes(field)
      ? Math.max(0.1, number)
      : number;
  }
};

// adding and changing elements
const newId = (type: string) =>
  `${type}-${Math.random().toString(36).slice(2, 8)}`;

const add = (type: LabelElement["type"]) => {
  const { width_mm: width, height_mm: height } = template.value;
  const square = Math.max(5, Math.min(width, height) / 2);
  const element: LabelElement = {
    id: newId(type),
    type,
    x: 2,
    y: 2,
    w: ["text", "line"].includes(type) ? Math.max(5, width - 4) : square,
    h: type === "text" ? 6 : type === "line" ? 1 : square,
    rotation: 0,
    ...(type === "text"
      ? {
          text: "{name}",
          font_size: 9,
          bold: false,
          align: "left",
          valign: "top",
          overflow: "shrink",
        }
      : {}),
    ...(type === "code" ? { symbology: "qrcode" } : {}),
    ...(type === "line" || type === "rect" ? { stroke: 0.3 } : {}),
  };
  template.value.elements.push(element);
  selectedId.value = element.id;
};

const rotate = () => {
  const element = selected.value!;
  // turn around the middle, the box swaps width and height
  const middleX = element.x + element.w / 2;
  const middleY = element.y + element.h / 2;
  [element.w, element.h] = [element.h, element.w];
  element.x = round(middleX - element.w / 2);
  element.y = round(middleY - element.h / 2);
  element.rotation = ((element.rotation + 90) %
    360) as LabelElement["rotation"];
};

const duplicate = () => {
  const copy = {
    ...selected.value!,
    id: newId(selected.value!.type),
    x: selected.value!.x + 2,
    y: selected.value!.y + 2,
  };
  template.value.elements.push(copy);
  selectedId.value = copy.id;
};

const remove = () => {
  template.value.elements = template.value.elements.filter(
    (element) => element.id !== selectedId.value,
  );
  selectedId.value = null;
};

const insertVariable = (variable: string) => {
  selected.value!.text = `${selected.value!.text ?? ""}{${variable}}`;
};

// moving and sizing with mouse or finger, in steps of 0.5 mm
const zoom = ref(8);
const snap = (value: number) => Math.round(value * 2) / 2;

const startDrag = (
  event: PointerEvent,
  element: LabelElement,
  mode: "move" | (typeof corners)[number],
) => {
  selectedId.value = element.id;
  const start = { x: event.clientX, y: event.clientY, element: { ...element } };
  const target = event.currentTarget as HTMLElement;
  target.setPointerCapture(event.pointerId);

  const move = (moveEvent: PointerEvent) => {
    const dx = (moveEvent.clientX - start.x) / zoom.value;
    const dy = (moveEvent.clientY - start.y) / zoom.value;
    const origin = start.element;
    if (mode === "move") {
      element.x = snap(origin.x + dx);
      element.y = snap(origin.y + dy);
      return;
    }
    if (mode.includes("e")) {
      element.w = Math.max(1, snap(origin.w + dx));
    } else {
      element.w = Math.max(1, snap(origin.w - dx));
      element.x = origin.x + origin.w - element.w;
    }
    if (mode.includes("s")) {
      element.h = Math.max(1, snap(origin.h + dy));
    } else {
      element.h = Math.max(1, snap(origin.h - dy));
      element.y = origin.y + origin.h - element.h;
    }
  };
  const stop = () => {
    target.removeEventListener("pointermove", move);
    target.removeEventListener("pointerup", stop);
    target.removeEventListener("pointercancel", stop);
  };
  target.addEventListener("pointermove", move);
  target.addEventListener("pointerup", stop);
  target.addEventListener("pointercancel", stop);
};

// arrow keys nudge, delete removes; not while typing in a field
const onKey = (event: KeyboardEvent) => {
  const field = (event.target as HTMLElement).closest(
    "input, textarea, select",
  );
  if (!selected.value || field) {
    return;
  }
  const step = event.shiftKey ? 0.1 : 1;
  const moves: Record<string, [number, number]> = {
    ArrowLeft: [-step, 0],
    ArrowRight: [step, 0],
    ArrowUp: [0, -step],
    ArrowDown: [0, step],
  };
  if (moves[event.key]) {
    event.preventDefault();
    selected.value.x = round(selected.value.x + moves[event.key][0]);
    selected.value.y = round(selected.value.y + moves[event.key][1]);
  } else if (event.key === "Delete" || event.key === "Backspace") {
    event.preventDefault();
    remove();
  } else if (event.key === "Escape") {
    selectedId.value = null;
  }
};

// zoom: fit the label into the width at the start
const stageBox = ref<HTMLElement | null>(null);
const zoomBy = (factor: number) => {
  zoom.value = Math.min(40, Math.max(2, zoom.value * factor));
};
const fitZoom = () => {
  const width = (stageBox.value?.clientWidth ?? 600) - 48;
  zoom.value = Math.min(16, Math.max(2, width / template.value.width_mm));
};

// drawing: the canvas is redrawn on every change
const canvas = ref<HTMLCanvasElement | null>(null);
const images = ref(new Map<string, HTMLImageElement | null>());
const problems = ref<string[]>([]);
const monochrome = computed(() => props.printer?.driver === "niimbot");
const unprintable = computed(() => {
  const head = monochrome.value ? props.printer?.model?.max_width_mm : null;
  return head && head < template.value.width_mm
    ? (template.value.width_mm - head) / 2
    : 0;
});

let frame = 0;
const draw = () => {
  cancelAnimationFrame(frame);
  frame = requestAnimationFrame(() => {
    if (!canvas.value) {
      return;
    }
    // thermal printers in their own dots, otherwise sharp on the screen
    const pxPerMm = monochrome.value
      ? (props.printer!.model?.dpi ?? 203) / 25.4
      : zoom.value * (window.devicePixelRatio || 1);
    problems.value = drawLabel(canvas.value, template.value, props.data, {
      pxPerMm,
      images: images.value,
      logoUrl: props.logoUrl,
      placeholders: true,
    });
    if (monochrome.value) {
      toMonochrome(canvas.value);
    }
  });
};

const reloadImages = async () => {
  images.value = await loadImages(
    [template.value],
    [props.data],
    props.logoUrl,
  );
  draw();
};

const imageKey = computed(() =>
  JSON.stringify([
    template.value.elements.map((element) => [element.type, element.path]),
    props.data.photo_url,
    props.logoUrl,
  ]),
);
watch(imageKey, reloadImages);
watch([template, () => props.data, () => props.printer, zoom], draw, {
  deep: true,
});

const warnings = computed(() => [
  ...templateWarnings(
    template.value,
    props.data,
    monochrome.value ? (props.printer?.model?.max_width_mm ?? null) : null,
  ),
  ...problems.value,
]);

// free images go to S3 through Stocks
const uploading = ref(false);
const uploadError = ref<string | null>(null);
const uploadImage = async (event: Event) => {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file || !selected.value) {
    return;
  }
  uploading.value = true;
  uploadError.value = null;
  const body = new FormData();
  body.append("image", file);
  const response = await fetch("/labels/images", {
    method: "POST",
    body,
    headers: { Accept: "application/json", "X-XSRF-TOKEN": xsrfToken() },
  });
  const result = await response.json();
  uploading.value = false;
  if (!response.ok) {
    uploadError.value =
      result.errors?.image?.[0] ??
      result.message ??
      "Das Hochladen ging schief.";
    return;
  }
  selected.value.path = result.path;
};
const xsrfToken = () =>
  decodeURIComponent(
    document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? "",
  );

onMounted(async () => {
  window.addEventListener("keydown", onKey);
  await nextTick();
  fitZoom();
  reloadImages();
});
onBeforeUnmount(() => {
  window.removeEventListener("keydown", onKey);
  cancelAnimationFrame(frame);
});

defineExpose({ select: (id: string | null) => (selectedId.value = id) });
</script>
