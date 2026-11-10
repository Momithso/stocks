<template>
  <UiPageHeader :title="item.name" :description="item.number">
    <UiButton
      v-if="can('inventory.move') && !item.retired_at"
      icon="right-left"
      @click="moveOpen = true"
    >
      Umlagern
    </UiButton>
    <UiButton
      v-if="consumable && can('inventory.move') && !item.retired_at"
      variant="secondary"
      icon="minus"
      @click="consumeOpen = true"
    >
      Verbraucht
    </UiButton>
    <UiButton
      v-if="can('inventory.move') && !item.retired_at"
      variant="secondary"
      icon="triangle-exclamation"
      @click="conditionOpen = true"
    >
      Zustand ändern
    </UiButton>
    <UiButton
      v-if="can('inventory.edit')"
      variant="secondary"
      icon="pen"
      :href="`/inventory/items/${item.id}/edit`"
    >
      Bearbeiten
    </UiButton>
    <UiButton
      v-if="can('inventory.retire') && !item.retired_at"
      variant="ghost"
      icon="box-archive"
      @click="retireOpen = true"
    >
      Ausmustern
    </UiButton>
    <UiButton
      v-if="can('inventory.retire')"
      variant="ghost"
      icon="trash"
      @click="deleteOpen = true"
    >
      Löschen
    </UiButton>
  </UiPageHeader>

  <div class="space-y-4">
    <UiAlert v-if="errorMessages.length" type="error">
      {{ errorMessages.join(" ") }}
    </UiAlert>
    <UiAlert v-if="item.retired_at" type="warning">
      Ausgemustert am
      <UiDateTime :value="item.retired_at" :with-time="false" /> ({{
        item.retired_reason
      }}).
      <template v-if="item.retired_note">{{ item.retired_note }}</template>
    </UiAlert>
    <UiAlert v-if="item.away_from_home" type="warning">
      Liegt nicht an seinem Stammplatz
      <InventoryPlaceLink :place="item.home" />.
      <button
        v-if="can('inventory.move')"
        type="button"
        class="ml-1 font-bold underline"
        @click="returnHome"
      >
        Zurück an den Stammplatz
      </button>
    </UiAlert>
    <UiAlert v-if="item.can_merge" type="info">
      Abgeteilt von <InventoryPlaceLink :place="item.split_from" />.
      <button
        v-if="can('inventory.move')"
        type="button"
        class="ml-1 font-bold underline"
        @click="mergeOpen = true"
      >
        Zurückbuchen
      </button>
    </UiAlert>
  </div>

  <div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
      <UiCard title="Daten">
        <dl class="grid gap-4 sm:grid-cols-2">
          <div>
            <dt class="text-sm text-ink-muted">Artikelnummer</dt>
            <dd class="font-mono font-semibold">{{ item.number }}</dd>
          </div>
          <div>
            <dt class="text-sm text-ink-muted">Art</dt>
            <dd>
              {{ item.kind_label }}
              <template v-if="item.kind === 'bulk'">
                · {{ item.quantity }} {{ item.unit }}
              </template>
              <template v-if="item.is_container"> · Box</template>
            </dd>
          </div>
          <div>
            <dt class="text-sm text-ink-muted">Kategorie</dt>
            <dd>{{ item.category ?? "—" }}</dd>
          </div>
          <div>
            <dt class="text-sm text-ink-muted">Zustand</dt>
            <dd>
              <UiBadge
                :variant="item.condition_is_defect ? 'danger' : 'neutral'"
              >
                {{ item.condition }}
              </UiBadge>
            </dd>
          </div>
          <div v-if="item.split_from">
            <dt class="text-sm text-ink-muted">Abgeteilt von</dt>
            <dd><InventoryPlaceLink :place="item.split_from" /></dd>
          </div>
          <div v-if="item.is_container">
            <dt class="text-sm text-ink-muted">Inhalt öffentlich</dt>
            <dd>{{ item.public_contents ? "ja" : "nein" }}</dd>
          </div>
          <div v-if="item.description" class="sm:col-span-2">
            <dt class="text-sm text-ink-muted">Beschreibung</dt>
            <dd class="whitespace-pre-line">{{ item.description }}</dd>
          </div>
          <div v-if="item.notes" class="sm:col-span-2">
            <dt class="text-sm text-ink-muted">Notiz</dt>
            <dd class="whitespace-pre-line">{{ item.notes }}</dd>
          </div>
        </dl>
      </UiCard>

      <UiCard v-if="item.is_container" title="Inhalt" flush>
        <div class="px-4 sm:px-6">
          <InventoryContentsTree
            v-if="item.contents.length"
            :items="item.contents"
          />
          <UiEmptyState v-else icon="box" title="Die Box ist leer" />
        </div>
      </UiCard>

      <UiCard title="Verlauf" flush>
        <!-- fixed height, the history scrolls in itself instead of making the page longer and longer -->
        <ul
          v-if="history.length"
          class="max-h-[28rem] divide-y divide-line overflow-y-auto"
        >
          <li
            v-for="entry in history"
            :key="entry.key"
            class="space-y-1 px-4 py-3 sm:px-6"
          >
            <p class="flex flex-wrap items-baseline gap-x-2">
              <span class="font-medium">{{ entry.title }}</span>
              <button
                v-if="can('inventory.history.delete')"
                type="button"
                class="order-last ml-auto text-ink-muted hover:text-red-600"
                @click="deletingEntry = entry"
              >
                <FontAwesomeIcon :icon="['fas', 'trash']" />
                <span class="sr-only">Eintrag löschen</span>
              </button>
              <span v-if="entry.quantity !== null" class="text-sm">
                {{ entry.quantity }} {{ item.unit }}
              </span>
              <span class="text-sm text-ink-muted">
                <UiDateTime :value="entry.at" />
                <template v-if="entry.user"> · {{ entry.user }}</template>
              </span>
            </p>
            <p v-if="entry.from || entry.to" class="text-sm">
              <InventoryPlaceLink :place="entry.from" empty="nirgends" />
              →
              <InventoryPlaceLink :place="entry.to" empty="nirgends" />
            </p>
            <p
              v-if="entry.text"
              class="whitespace-pre-line text-sm text-ink-muted"
            >
              {{ entry.text }}
            </p>
            <div v-if="entry.photos.length" class="flex flex-wrap gap-2 pt-1">
              <a
                v-for="photo in entry.photos"
                :key="photo.id"
                :href="photo.url"
                target="_blank"
              >
                <img
                  :src="photo.url"
                  alt=""
                  class="h-16 w-16 rounded object-cover"
                />
              </a>
            </div>
          </li>
        </ul>
        <UiEmptyState
          v-else
          icon="clock-rotate-left"
          title="Noch kein Verlauf"
        />
      </UiCard>
    </div>

    <!-- place first on phones, it is what people look for -->
    <div class="order-first space-y-6 lg:order-none">
      <UiCard title="Ort">
        <dl class="space-y-4">
          <div>
            <dt class="text-sm text-ink-muted">Liegt in</dt>
            <dd><InventoryPlaceLink :place="item.place" empty="kein Ort" /></dd>
          </div>
          <div v-if="item.location">
            <dt class="text-sm text-ink-muted">Standort der Box</dt>
            <dd><InventoryPlaceLink :place="item.location" /></dd>
          </div>
          <div>
            <dt class="text-sm text-ink-muted">Stammplatz</dt>
            <dd><InventoryPlaceLink :place="item.home" empty="keiner" /></dd>
          </div>
        </dl>
      </UiCard>

      <UiCard title="Fotos">
        <!-- the first photo is the preview in lists: shown large, with an accent frame and a label -->
        <div v-if="item.photos.length" class="grid grid-cols-2 gap-4">
          <figure
            v-for="(photo, index) in item.photos"
            :key="photo.id"
            :class="['space-y-2', index === 0 ? 'col-span-2' : '']"
          >
            <div class="relative">
              <a :href="photo.url" target="_blank" class="block">
                <img
                  :src="photo.url"
                  alt=""
                  :class="[
                    'aspect-square w-full rounded-md object-cover',
                    index === 0
                      ? 'ring-2 ring-accent ring-offset-2 ring-offset-surface'
                      : '',
                  ]"
                />
              </a>
              <span
                v-if="index === 0"
                class="pointer-events-none absolute left-2 top-2 inline-flex items-center gap-1 rounded-full bg-accent px-2.5 py-1 text-xs font-semibold text-white shadow ring-2 ring-white"
              >
                <FontAwesomeIcon :icon="['fas', 'star']" />
                Vorschaubild
              </span>
              <InertiaLink
                v-if="can('inventory.edit')"
                :href="`/inventory/items/${item.id}/photos/${photo.id}`"
                method="delete"
                as="button"
                preserve-scroll
                class="absolute right-2 top-2 flex h-8 w-8 items-center justify-center rounded-full bg-surface/90 text-ink-muted shadow hover:text-red-600"
              >
                <FontAwesomeIcon :icon="['fas', 'trash']" />
                <span class="sr-only">Foto löschen</span>
              </InertiaLink>
            </div>
            <UiButton
              v-if="can('inventory.edit') && index > 0"
              variant="secondary"
              size="sm"
              icon="star"
              class="w-full whitespace-nowrap !px-2 !text-xs"
              :href="`/inventory/items/${item.id}/photos/${photo.id}/cover`"
              method="post"
            >
              Als Vorschau
            </UiButton>
            <figcaption v-else-if="index === 0" class="text-xs text-ink-muted">
              Erscheint in Listen und Suchergebnissen.
            </figcaption>
          </figure>
        </div>
        <p v-else class="text-sm text-ink-muted">Noch keine Fotos.</p>

        <form
          v-if="can('inventory.edit') && item.photos.length < maxPhotos"
          class="mt-4"
          @submit.prevent
        >
          <label class="block">
            <span class="sr-only">Fotos hochladen</span>
            <input
              type="file"
              accept="image/jpeg,image/png,image/webp"
              multiple
              class="block w-full text-sm text-ink-muted file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-accent file:px-3 file:py-2 file:text-sm file:font-medium file:text-white"
              :disabled="photoForm.processing"
              @change="uploadPhotos"
            />
          </label>
          <p v-if="photoError" class="mt-1 text-sm text-red-600">
            {{ photoError }}
          </p>
        </form>
      </UiCard>
    </div>
  </div>

  <!-- move -->
  <UiModal v-if="moveOpen" title="Umlagern" @close="moveOpen = false">
    <form class="space-y-5" @submit.prevent="move">
      <InventoryPlaceInput
        v-model="moveForm.target_code"
        label="Ziel"
        required
        :error="moveForm.errors.target_code ?? moveForm.errors.target"
      />
      <UiInput
        v-if="item.kind === 'bulk'"
        v-model="moveForm.quantity"
        label="Menge"
        type="number"
        inputmode="numeric"
        :hint="`Von ${item.quantity} ${item.unit ?? ''}. Eine Teilmenge wird ein eigener Gegenstand mit eigener Nummer.`"
        :error="moveForm.errors.quantity"
      />
      <UiInput
        v-model="moveForm.note"
        label="Notiz"
        :error="moveForm.errors.note"
      />
      <UiButton type="submit" class="w-full" :disabled="moveForm.processing">
        Umlagern
      </UiButton>
    </form>
  </UiModal>

  <!-- condition -->
  <UiModal
    v-if="conditionOpen"
    title="Zustand ändern"
    @close="conditionOpen = false"
  >
    <form class="space-y-5" @submit.prevent="changeCondition">
      <UiSelect
        v-model="conditionForm.condition_id"
        label="Neuer Zustand"
        :options="conditions"
        :placeholder="null"
      />
      <UiTextarea
        v-model="conditionForm.reason"
        label="Warum?"
        required
        placeholder="z. B. Gehäuse beim Abbau gebrochen"
        :error="
          conditionForm.errors.reason ?? conditionForm.errors.condition_id
        "
      />
      <label class="block text-sm font-medium">
        Fotos (empfohlen)
        <input
          type="file"
          accept="image/jpeg,image/png,image/webp"
          multiple
          class="mt-1 block w-full text-sm font-normal text-ink-muted file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-surface-muted file:px-3 file:py-2 file:text-sm file:font-medium file:text-ink"
          @change="setConditionPhotos"
        />
      </label>
      <p v-if="photoErrorOf(conditionForm.errors)" class="text-sm text-red-600">
        {{ photoErrorOf(conditionForm.errors) }}
      </p>
      <UiButton
        type="submit"
        class="w-full"
        :disabled="conditionForm.processing"
      >
        Speichern
      </UiButton>
    </form>
  </UiModal>

  <!-- merge: book a split off part back to its origin -->
  <UiModal v-if="mergeOpen" title="Zurückbuchen" @close="mergeOpen = false">
    <form class="space-y-5" @submit.prevent="merge">
      <UiInput
        v-model="mergeForm.quantity"
        label="Menge"
        type="number"
        inputmode="numeric"
        required
        :hint="`Von ${item.quantity} ${item.unit ?? ''}. Die Menge kommt zurück zu ${item.split_from?.code}; bei allem wird dieser Teil ausgemustert.`"
        :error="mergeForm.errors.quantity ?? mergeForm.errors.item"
      />
      <UiButton type="submit" class="w-full" :disabled="mergeForm.processing">
        Zurückbuchen
      </UiButton>
    </form>
  </UiModal>

  <!-- consume: quick action for consumables -->
  <UiModal v-if="consumeOpen" title="Verbraucht" @close="consumeOpen = false">
    <form class="space-y-5" @submit.prevent="consume">
      <UiInput
        v-model="consumeForm.quantity"
        label="Wie viel wurde verbraucht?"
        type="number"
        inputmode="numeric"
        required
        :hint="`Noch da: ${item.quantity} ${item.unit ?? ''}`"
        :error="consumeForm.errors.quantity"
      />
      <UiInput
        v-model="consumeForm.note"
        label="Wofür? (optional)"
        :error="consumeForm.errors.note"
      />
      <UiButton type="submit" class="w-full" :disabled="consumeForm.processing">
        Als verbraucht ausbuchen
      </UiButton>
    </form>
  </UiModal>

  <!-- retire -->
  <UiModal v-if="retireOpen" title="Ausmustern" @close="retireOpen = false">
    <form class="space-y-5" @submit.prevent="retire">
      <UiInput
        v-if="item.kind === 'bulk'"
        v-model="retireForm.quantity"
        label="Menge"
        type="number"
        inputmode="numeric"
        :hint="`Von ${item.quantity} ${item.unit ?? ''}. Eine Teilmenge wird abgezogen, der Rest bleibt.`"
        :error="retireForm.errors.quantity"
      />
      <p class="text-sm text-ink-muted">
        {{
          isPartialRetire
            ? "Die Menge wird abgezogen und mit Grund im Verlauf vermerkt."
            : "Der Gegenstand behält Nummer, Fotos und Verlauf, hat aber keinen Ort mehr und verschwindet aus den Listen."
        }}
      </p>
      <UiSelect
        v-model="retireForm.reason"
        label="Grund"
        :options="retireReasons"
        :placeholder="null"
      />
      <UiTextarea
        v-model="retireForm.note"
        label="Notiz"
        :error="retireForm.errors.note"
      />
      <UiButton
        type="submit"
        variant="danger"
        class="w-full"
        :disabled="retireForm.processing"
      >
        {{ isPartialRetire ? "Menge ausbuchen" : "Ausmustern" }}
      </UiButton>
    </form>
  </UiModal>

  <UiConfirmModal
    v-if="deletingEntry"
    title="Eintrag löschen"
    :message="`„${deletingEntry.title}“ wird aus dem Verlauf gelöscht, mit seinen Fotos. Ort und Zustand des Gegenstands bleiben, wie sie sind.`"
    @close="deletingEntry = null"
    @confirm="destroyEntry"
  />

  <UiConfirmModal
    v-if="deleteOpen"
    title="Gegenstand löschen"
    :message="`${item.number} wird endgültig gelöscht. Das geht nur bei Fehleingaben ohne Verlauf; sonst ausmustern.`"
    @close="deleteOpen = false"
    @confirm="destroy"
  />
</template>

<script setup lang="ts">
import { computed, ref } from "vue";
import { Link as InertiaLink, router, useForm, usePage } from "@inertiajs/vue3";

type Photo = { id: number; url: string };

/**
 * Detail page of an item: data, place, contents, photos and history.
 */
type Props = {
  item: {
    id: number;
    number: string;
    name: string;
    description: string | null;
    kind: "single" | "bulk";
    kind_label: string;
    quantity: number;
    unit: string | null;
    is_container: boolean;
    public_contents: boolean;
    condition_id: number;
    condition: string;
    condition_is_defect: boolean;
    category: string | null;
    notes: string | null;
    place: Place | null;
    home: Place | null;
    location: Place | null;
    away_from_home: boolean;
    split_from: Place | null;
    // split off part that can be booked back to split_from
    can_merge: boolean;
    retired_at: string | null;
    retired_reason: string | null;
    retired_note: string | null;
    photos: Photo[];
    contents: ContentsItem[];
  };
  history: {
    key: string;
    at: string;
    title: string;
    from: Place | null;
    to: Place | null;
    quantity: number | null;
    text: string | null;
    user: string | null;
    photos: Photo[];
    delete_url: string;
  }[];
  conditions: Option[];
  retireReasons: Option[];
  // bulk item whose unit is marked as consumable
  consumable: boolean;
  maxPhotos: number;
};

const props = defineProps<Props>();

const page = usePage<SharedProps>();
const { can } = usePermissions();
const { compressImages } = useImageCompression();

// errors of actions without own form field, e.g. "In der Box liegen noch Gegenstände."
const errorMessages = computed(() =>
  ["item", "target"].map((key) => page.props.errors?.[key]).filter(Boolean),
);

const photoErrorOf = (errors: Record<string, string>) =>
  Object.entries(errors).find(([key]) => key.startsWith("photos"))?.[1] ?? null;

// move
const moveOpen = ref(false);
const moveForm = useForm({
  item_id: props.item.id,
  target_code: "",
  quantity: props.item.quantity as number | null,
  note: "",
});
const move = () =>
  // the id is taken when sending, the page can show another item by now
  moveForm
    .transform((data) => ({ ...data, item_id: props.item.id }))
    .post("/inventory/move", {
      preserveScroll: true,
      onSuccess: () => {
        moveOpen.value = false;
        moveForm.reset();
      },
    });

const returnHome = () =>
  router.post(
    `/inventory/items/${props.item.id}/return-home`,
    {},
    { preserveScroll: true },
  );

// condition
const conditionOpen = ref(false);
const conditionForm = useForm({
  // preselect another condition than the current one
  condition_id:
    props.conditions.find((option) => option.value !== props.item.condition_id)
      ?.value ?? null,
  reason: "",
  photos: [] as File[],
});
const setConditionPhotos = async (event: Event) => {
  conditionForm.photos = await compressImages(
    (event.target as HTMLInputElement).files,
  );
};
const changeCondition = () =>
  conditionForm.post(`/inventory/items/${props.item.id}/condition`, {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => {
      conditionOpen.value = false;
      conditionForm.reset();
    },
  });

// photos
const photoForm = useForm({ photos: [] as File[] });
const photoError = computed(() => photoErrorOf(photoForm.errors));
const uploadPhotos = async (event: Event) => {
  const input = event.target as HTMLInputElement;
  photoForm.photos = await compressImages(input.files);
  photoForm.post(`/inventory/items/${props.item.id}/photos`, {
    preserveScroll: true,
    forceFormData: true,
    onFinish: () => {
      input.value = "";
    },
  });
};

// retire and delete
const retireOpen = ref(false);
// book a split off part back to its origin
const mergeOpen = ref(false);
const mergeForm = useForm({ quantity: props.item.quantity as number | null });
const merge = () =>
  mergeForm.post(`/inventory/items/${props.item.id}/merge`, {
    // the server shows the origin next: start with fresh forms for that item
    preserveState: false,
  });

// consume a part of a consumable ("verbraucht")
const consumeOpen = ref(false);
const consumeForm = useForm({ quantity: 1 as number | null, note: "" });
const consume = () =>
  consumeForm.post(`/inventory/items/${props.item.id}/consume`, {
    preserveScroll: true,
    onSuccess: () => {
      consumeOpen.value = false;
      consumeForm.reset();
    },
  });

// bulk items start with the whole quantity; less than all is a partial retire
const retireForm = useForm({
  reason: "defective",
  note: "",
  quantity: props.item.quantity as number | null,
});
const isPartialRetire = computed(
  () =>
    props.item.kind === "bulk" &&
    Number(retireForm.quantity) < props.item.quantity,
);
const retire = () =>
  retireForm.post(`/inventory/items/${props.item.id}/retire`, {
    preserveScroll: true,
    onSuccess: () => {
      retireOpen.value = false;
      retireForm.note = "";
      retireForm.quantity = props.item.quantity;
    },
  });

// delete a wrong entry of the history
const deletingEntry = ref<Props["history"][number] | null>(null);
const destroyEntry = () =>
  router.delete(deletingEntry.value!.delete_url, {
    preserveScroll: true,
    onFinish: () => (deletingEntry.value = null),
  });

const deleteOpen = ref(false);
const destroy = () =>
  router.delete(`/inventory/items/${props.item.id}`, {
    onFinish: () => (deleteOpen.value = false),
  });
</script>
