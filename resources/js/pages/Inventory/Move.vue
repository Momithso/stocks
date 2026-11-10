<template>
  <UiPageHeader
    title="Umlagern"
    description="Erst den Gegenstand oder die Box scannen, dann das Ziel. Ein Handscanner tippt den Code wie eine Tastatur."
  />

  <div class="grid gap-6 lg:grid-cols-2">
    <UiCard>
      <form class="space-y-5" @submit.prevent="next">
        <ol class="flex gap-2 text-sm">
          <li
            :class="[
              'rounded-full px-3 py-1',
              item ? 'bg-surface-muted text-ink-muted' : 'bg-accent text-white',
            ]"
          >
            1 · Gegenstand
          </li>
          <li
            :class="[
              'rounded-full px-3 py-1',
              item ? 'bg-accent text-white' : 'bg-surface-muted text-ink-muted',
            ]"
          >
            2 · Ziel
          </li>
        </ol>

        <!-- camera: the first scan picks the item, the second the target, as with typing -->
        <InventoryCameraScanner
          v-if="cameraOpen"
          autostart
          class="aspect-video w-full"
          @scan="scanWithCamera"
          @stopped="cameraOpen = false"
        />

        <div v-if="item" class="rounded-md bg-surface-muted p-3 text-sm">
          <p class="font-medium">{{ item.name }}</p>
          <p class="font-mono text-ink-muted">{{ item.number }}</p>
          <p class="text-ink-muted">
            liegt in
            <InventoryPlaceLink :place="item.place" empty="keinem Ort" />
          </p>
        </div>

        <UiInput
          v-if="item && item.kind === 'bulk'"
          v-model="quantity"
          label="Menge"
          type="number"
          inputmode="numeric"
          :hint="`Von ${item.quantity} ${item.unit ?? ''}. Eine Teilmenge wird ein eigener Gegenstand.`"
          :error="form.errors.quantity"
        />

        <div>
          <label for="scan-code" class="block text-sm font-medium">
            {{ item ? "Code des Ziels" : "Code des Gegenstands" }}
          </label>
          <input
            id="scan-code"
            ref="input"
            v-model="code"
            type="text"
            autocomplete="off"
            autocapitalize="characters"
            :placeholder="item ? 'z. B. EU-014-R03.4' : 'z. B. FSR5-004218'"
            class="mt-1 block w-full rounded-md border-0 bg-surface py-3 font-mono text-lg text-ink shadow-sm ring-1 ring-inset ring-line focus:ring-2 focus:ring-inset focus:ring-accent"
          />
          <p v-if="error" class="mt-1 text-sm text-red-600">{{ error }}</p>
        </div>

        <div class="flex gap-3">
          <UiButton type="submit" class="flex-1" size="lg" :disabled="busy">
            {{ item ? "Umlagern" : "Weiter" }}
          </UiButton>
          <UiButton
            v-if="!cameraOpen"
            variant="secondary"
            size="lg"
            icon="camera"
            @click="cameraOpen = true"
          >
            <span class="sr-only sm:not-sr-only">Kamera</span>
          </UiButton>
          <UiButton v-if="item" variant="secondary" size="lg" @click="restart">
            Abbrechen
          </UiButton>
        </div>
      </form>
    </UiCard>

    <UiCard title="Zuletzt umgelagert" flush>
      <ul v-if="log.length" class="divide-y divide-line">
        <li
          v-for="(entry, index) in log"
          :key="index"
          class="px-4 py-3 text-sm sm:px-6"
        >
          {{ entry }}
        </li>
      </ul>
      <UiEmptyState v-else icon="right-left" title="Noch nichts umgelagert" />
    </UiCard>
  </div>
</template>

<script setup lang="ts">
import { nextTick, onMounted, ref } from "vue";
import { useForm, usePage } from "@inertiajs/vue3";

type ScannedItem = {
  id: number;
  number: string;
  name: string;
  kind: "single" | "bulk";
  quantity: number;
  unit: string | null;
  retired: boolean;
  place: Place | null;
};

/**
 * Moving by scan in two steps: item, then target. After every move the page waits for the next
 * scan, so a box of material can be put away one item after the other.
 */
const page = usePage<SharedProps>();

const input = ref<HTMLInputElement | null>(null);
const code = ref("");
const error = ref<string | null>(null);
const busy = ref(false);
const item = ref<ScannedItem | null>(null);
const quantity = ref<number | null>(null);
const log = ref<string[]>([]);
const cameraOpen = ref(false);

const form = useForm({
  item_id: 0,
  target_code: "",
  quantity: null as number | null,
});

// with the camera on, a focused field would open the keyboard of phones
const focus = () =>
  nextTick(() => {
    if (!cameraOpen.value) {
      input.value?.focus();
    }
  });
onMounted(focus);

const restart = () => {
  item.value = null;
  code.value = "";
  error.value = null;
  focus();
};

const next = async () => {
  const scanned = code.value.trim();
  if (!scanned) {
    return;
  }
  error.value = null;

  if (item.value) {
    moveTo(scanned);
    return;
  }

  // step 1: find the item
  busy.value = true;
  const response = await fetch(
    `/inventory/lookup?code=${encodeURIComponent(scanned)}`,
    {
      headers: { Accept: "application/json" },
    },
  );
  busy.value = false;
  const data = await response.json();

  if (!response.ok) {
    error.value = data.message;
  } else if (data.type !== "item") {
    error.value = `${data.full_code} ist ein Standort. Scanne zuerst den Gegenstand.`;
  } else if (data.retired) {
    error.value = `${data.number} ist ausgemustert.`;
  } else {
    item.value = data;
    quantity.value = data.quantity;
  }

  code.value = "";
  focus();
};

const scanWithCamera = (scanned: string) => {
  code.value = scanned;
  next();
};

// step 2: move the item to the target
const moveTo = (targetCode: string) => {
  form.item_id = item.value!.id;
  form.target_code = targetCode;
  form.quantity = item.value!.kind === "bulk" ? quantity.value : null;
  form.post("/inventory/move", {
    preserveScroll: true,
    onSuccess: () => {
      if (page.props.flash.success) {
        log.value = [page.props.flash.success, ...log.value].slice(0, 20);
      }
      restart();
    },
    onError: (errors) => {
      error.value = errors.target_code ?? errors.target ?? errors.item ?? null;
      code.value = "";
      focus();
    },
  });
};
</script>
