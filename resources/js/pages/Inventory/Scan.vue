<template>
  <UiPageHeader title="Scannen" />

  <div class="grid gap-6 lg:grid-cols-2">
    <div class="flex flex-col gap-4">
      <!-- typing or a hand scanner; on the pc the scanner also works without clicking the field -->
      <form class="flex gap-2 lg:order-last" @submit.prevent="submitTyped">
        <input
          v-model="typed"
          type="text"
          autocomplete="off"
          autocapitalize="characters"
          placeholder="Code eintippen, z. B. FSR5-004218"
          aria-label="Code"
          class="block w-full rounded-md border-0 bg-surface py-2.5 font-mono text-ink shadow-sm ring-1 ring-inset ring-line focus:ring-2 focus:ring-inset focus:ring-accent"
        />
        <UiButton type="submit" variant="secondary">Suchen</UiButton>
      </form>
      <!-- on phones the camera fills the screen and shrinks to the upper part once a result is shown,
           so it stays visible above the card and keeps scanning -->
      <InventoryCameraScanner
        :class="[
          'w-full transition-[height] duration-300 lg:aspect-video lg:h-auto',
          hasResult
            ? 'h-[36dvh]'
            : 'h-[calc(100dvh-14rem-var(--bottom-bar)-env(safe-area-inset-bottom))]',
        ]"
        :autostart="autostart"
        @scan="scanned"
        @started="rememberCamera(true)"
        @stopped="rememberCamera(false)"
      >
        <template #actions="{ item }">
          <button
            v-if="card || error"
            type="button"
            :class="[item, 'lg:hidden']"
            @click="closeSheet"
          >
            <FontAwesomeIcon :icon="['fas', 'expand']" class="h-4" /> Kamera
            groß
          </button>
        </template>
      </InventoryCameraScanner>
    </div>

    <div class="space-y-4">
      <!-- second step of moving, or putting everything at one place -->
      <UiAlert v-if="mode?.type === 'move'" type="info">
        <strong>Umlagern:</strong> {{ mode.item.number }} {{ mode.item.name }}
        <template v-if="mode.item.kind === 'bulk'">
          ({{ moveQuantity }} {{ mode.item.unit }})</template
        >. Scanne jetzt das Ziel.
        <button
          type="button"
          class="ml-1 font-bold underline"
          @click="mode = null"
        >
          Abbrechen
        </button>
      </UiAlert>
      <UiAlert v-if="mode?.type === 'here'" type="info">
        <strong>Hierher umlagern:</strong> Jeder gescannte Gegenstand kommt nach
        {{ mode.code }}.
        <button
          type="button"
          class="ml-1 font-bold underline"
          @click="mode = null"
        >
          Beenden
        </button>
      </UiAlert>
      <UiAlert v-if="error" type="error">{{ error }}</UiAlert>

      <!-- the card of the last scan -->
      <UiCard v-if="card?.type === 'item'">
        <div class="flex gap-4">
          <img
            v-if="card.photo_url"
            :src="card.photo_url"
            alt=""
            class="h-24 w-24 shrink-0 rounded-md object-cover"
          />
          <div class="min-w-0 space-y-1">
            <p class="text-lg font-semibold">{{ card.name }}</p>
            <p class="font-mono text-sm text-ink-muted">{{ card.number }}</p>
            <p class="text-sm">
              <template v-if="card.kind === 'bulk'"
                >{{ card.quantity }} {{ card.unit }} ·
              </template>
              <template v-if="card.is_container"
                >Box mit {{ card.contents_count }} Gegenständen ·
              </template>
              <InventoryPlaceLink :place="card.place" empty="kein Ort" />
            </p>
            <UiBadge :variant="card.condition_is_defect ? 'danger' : 'neutral'">
              {{ card.condition }}
            </UiBadge>
            <UiBadge v-if="card.retired" class="ml-2">ausgemustert</UiBadge>
          </div>
        </div>
        <!-- notices of modules, e.g. "ausgegeben an … bis …" -->
        <template v-for="(notice, index) in card.notices" :key="index">
          <UiAlert
            v-if="notice.text"
            :type="notice.variant === 'danger' ? 'error' : notice.variant"
            class="mt-4"
          >
            {{ notice.text }}
          </UiAlert>
        </template>
        <UiInput
          v-if="card.kind === 'bulk' && canMove"
          v-model="moveQuantity"
          class="mt-4"
          label="Menge zum Umlagern"
          type="number"
          inputmode="numeric"
          :hint="`Von ${card.quantity} ${card.unit ?? ''}. Eine Teilmenge wird ein eigener Gegenstand.`"
        />
        <div
          class="mt-4 grid grid-cols-2 gap-2 [&>*]:min-h-[44px] lg:[&>*]:text-base"
        >
          <!-- actions of modules, e.g. "Zur Buchung hinzufügen" -->
          <template v-for="notice in card.notices" :key="notice.text">
            <UiButton
              v-for="action in notice.actions"
              :key="action.href"
              :icon="action.icon"
              size="md"
              :href="action.href"
            >
              {{ action.title }}
            </UiButton>
          </template>
          <UiButton
            v-if="canMove && !isLocked"
            icon="right-left"
            size="md"
            @click="startMove"
            >Umlagern</UiButton
          >
          <UiButton
            v-if="canMove && card.consumable"
            variant="secondary"
            icon="minus"
            size="md"
            @click="consumeOpen = true"
          >
            Verbraucht
          </UiButton>
          <UiButton
            v-if="canMove"
            variant="secondary"
            icon="triangle-exclamation"
            size="md"
            @click="conditionOpen = true"
          >
            Zustand
          </UiButton>
          <UiButton
            v-if="canMove && card.can_merge"
            variant="secondary"
            icon="rotate"
            size="md"
            @click="mergeOpen = true"
          >
            Zurückbuchen
          </UiButton>
          <UiButton
            v-if="can('labels.print') && !card.retired"
            variant="secondary"
            icon="tag"
            size="md"
            :href="`/labels/print?items=${card.id}`"
          >
            Etikett
          </UiButton>
          <UiButton
            variant="secondary"
            icon="arrow-up-right-from-square"
            size="md"
            :href="card.href"
          >
            {{ card.is_container ? "Inhalt" : "Öffnen" }}
          </UiButton>
        </div>
      </UiCard>

      <UiCard v-else-if="card?.type === 'location'">
        <p class="font-mono text-lg font-semibold">{{ card.full_code }}</p>
        <p class="text-sm text-ink-muted">
          {{ [card.type_label, card.name].filter(Boolean).join(" · ") }} ·
          {{ card.items_count }} Gegenstände
        </p>
        <div
          class="mt-4 grid grid-cols-2 gap-2 [&>*]:min-h-[44px] lg:[&>*]:text-base"
        >
          <UiButton
            v-if="can('inventory.move') && card.location_type !== 'address'"
            icon="right-left"
            size="md"
            @click="mode = { type: 'here', code: card.full_code }"
          >
            Hierher umlagern
          </UiButton>
          <UiButton
            v-if="can('labels.print')"
            variant="secondary"
            icon="tag"
            size="md"
            :href="`/labels/print?locations=${card.id}`"
          >
            Etikett
          </UiButton>
          <UiButton
            variant="secondary"
            icon="arrow-up-right-from-square"
            size="md"
            :href="card.href"
          >
            Öffnen
          </UiButton>
        </div>
      </UiCard>

      <UiCard v-else-if="card?.type === 'reserved'">
        <p class="font-mono text-lg font-semibold">{{ card.number }}</p>
        <p class="text-sm text-ink-muted">{{ card.message }}</p>
        <UiButton
          v-if="can('inventory.edit')"
          class="mt-4"
          icon="plus"
          size="md"
          :href="card.href"
        >
          Gegenstand anlegen
        </UiButton>
      </UiCard>

      <UiEmptyState
        v-else-if="!error"
        icon="qrcode"
        title="Noch nichts gescannt"
        description="Halte die Kamera auf den Code eines Etiketts oder scanne mit dem Handscanner, dann entscheide, was damit passiert."
      />
    </div>
  </div>

  <InventoryConsumeModal
    v-if="consumeOpen && card?.type === 'item'"
    :item="card"
    @close="consumeOpen = false"
    @done="afterAction"
  />
  <InventoryConditionModal
    v-if="conditionOpen && card?.type === 'item'"
    :item="card"
    :conditions="conditions"
    @close="conditionOpen = false"
    @done="afterAction"
  />
  <UiModal
    v-if="mergeOpen && card?.type === 'item'"
    title="Zurückbuchen"
    @close="mergeOpen = false"
  >
    <form class="space-y-5" @submit.prevent="merge">
      <UiInput
        v-model="mergeQuantity"
        label="Menge"
        type="number"
        inputmode="numeric"
        required
        :hint="`Von ${card.quantity} ${card.unit ?? ''}. Die Menge kommt zurück zu ${card.split_from?.code}.`"
      />
      <UiButton type="submit" class="w-full">Zurückbuchen</UiButton>
    </form>
  </UiModal>
</template>

<script setup lang="ts">
import { computed, ref } from "vue";
import { router } from "@inertiajs/vue3";

type ScannedItem = {
  type: "item";
  id: number;
  number: string;
  name: string;
  kind: "single" | "bulk";
  quantity: number;
  unit: string | null;
  is_container: boolean;
  retired: boolean;
  place: Place | null;
  condition_id: number;
  condition: string;
  condition_is_defect: boolean;
  photo_url: string | null;
  contents_count: number;
  consumable: boolean;
  can_merge: boolean;
  split_from: Place | null;
  href: string;
  notices: ItemNotice[];
};
type ScannedLocation = {
  type: "location";
  location_type: "address" | "room" | "shelf" | "compartment";
  id: number;
  type_label: string;
  name: string | null;
  full_code: string;
  items_count: number;
  href: string;
};
type ScannedReserved = {
  type: "reserved";
  number: string;
  message: string;
  href: string;
};
type Scanned = ScannedItem | ScannedLocation | ScannedReserved;

/**
 * Scan page: the camera keeps running, every hit shows a card with the actions for the item, box or
 * location. "Umlagern" makes the next scan the target; "Hierher umlagern" at a location books every
 * following item there until it is ended.
 */
defineProps<{ conditions: Option[] }>();

const { can } = usePermissions();

const card = ref<Scanned | null>(null);
const error = ref<string | null>(null);
const mode = ref<
  { type: "move"; item: ScannedItem } | { type: "here"; code: string } | null
>(null);
const moveQuantity = ref<number | string>(1);

const canMove = computed(
  () =>
    card.value?.type === "item" && !card.value.retired && can("inventory.move"),
);

// a module locks the item, e.g. while it is lent out
const isLocked = computed(
  () =>
    card.value?.type === "item" &&
    card.value.notices.some((notice) => notice.lock),
);

// the camera starts by itself when it was on last time
const readCamera = () => {
  try {
    return localStorage.getItem("scanCamera") === "1";
  } catch {
    return false;
  }
};
const autostart = readCamera();
const rememberCamera = (on: boolean) => {
  try {
    localStorage.setItem("scanCamera", on ? "1" : "0");
  } catch {
    // private mode: not remembered
  }
};

const lookup = async (code: string): Promise<Scanned | null> => {
  const response = await fetch(
    `/inventory/lookup?code=${encodeURIComponent(code)}`,
    {
      headers: { Accept: "application/json" },
    },
  );
  const data = await response.json();
  if (!response.ok) {
    error.value = data.message;
    return null;
  }
  return data;
};

const show = async (code: string) => {
  const found = await lookup(code);
  if (found) {
    card.value = found;
    if (found.type === "item") {
      moveQuantity.value = found.quantity;
      mergeQuantity.value = found.quantity;
    }
  }
};

// a move: by the next scan (mode "move") or to the place of mode "here"
const move = (
  itemId: number,
  targetCode: string,
  quantity: number | null,
  onSuccess?: () => void,
) =>
  router.post(
    "/inventory/move",
    { item_id: itemId, target_code: targetCode, quantity },
    {
      preserveScroll: true,
      preserveState: true,
      onSuccess,
      onError: (errors) => {
        error.value =
          errors.target_code ??
          errors.target ??
          errors.item ??
          errors.quantity ??
          null;
      },
    },
  );

const scanned = async (code: string) => {
  error.value = null;

  if (mode.value?.type === "move") {
    const item = mode.value.item;
    mode.value = null;
    // afterwards the card shows the item with its new place
    move(
      item.id,
      code,
      item.kind === "bulk" ? Number(moveQuantity.value) : null,
      () => show(item.number),
    );
    return;
  }

  if (mode.value?.type === "here") {
    const found = await lookup(code);
    if (found?.type === "item") {
      card.value = found;
      move(found.id, mode.value.code, null, () => show(found.number));
    } else if (found) {
      error.value =
        "Das ist kein Gegenstand. „Hierher umlagern“ beenden, um etwas anderes zu scannen.";
    }
    return;
  }

  await show(code);
};

useScannerInput(scanned);
const typed = ref("");
const submitTyped = () => {
  if (typed.value.trim()) {
    scanned(typed.value.trim());
    typed.value = "";
  }
};

const startMove = () => {
  if (card.value?.type === "item") {
    mode.value = { type: "move", item: card.value };
  }
};

const hasResult = computed(() => !!(card.value || mode.value || error.value));

// phones: hide the card again to see more of the camera
const closeSheet = () => {
  card.value = null;
  error.value = null;
};

// dialogs
const consumeOpen = ref(false);
const conditionOpen = ref(false);
const mergeOpen = ref(false);
const mergeQuantity = ref<number | string>(1);
const afterAction = () => {
  consumeOpen.value = false;
  conditionOpen.value = false;
  mergeOpen.value = false;
  if (card.value?.type === "item") {
    show(card.value.number);
  }
};
// booking back is a move to the origin (see MoveController), so the scan page stays open
const merge = () => {
  if (card.value?.type !== "item" || !card.value.split_from) {
    return;
  }
  const origin = card.value.split_from.code;
  move(card.value.id, origin, Number(mergeQuantity.value) || null, () => {
    mergeOpen.value = false;
    show(origin);
  });
};
</script>
