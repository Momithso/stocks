<template>
  <UiPageHeader
    :title="item ? `${item.number} bearbeiten` : 'Neuer Gegenstand'"
  >
    <UiButton
      :href="item ? `/inventory/items/${item.id}` : '/inventory/items'"
      variant="secondary"
    >
      Zurück
    </UiButton>
  </UiPageHeader>

  <form class="space-y-6" @submit.prevent="submit">
    <UiCard>
      <div class="grid gap-5 sm:grid-cols-2">
        <UiInput
          v-model="form.name"
          label="Name"
          required
          class="sm:col-span-2"
          :error="form.errors.name"
        />
        <UiSelect
          v-model="form.category_id"
          label="Kategorie"
          :options="categories"
          placeholder="keine"
        />
        <UiSelect
          v-model="form.kind"
          label="Art"
          :options="kinds"
          :placeholder="null"
        />
        <template v-if="form.kind === 'bulk'">
          <UiInput
            v-model="form.quantity"
            label="Menge"
            type="number"
            inputmode="numeric"
            required
            :hint="
              item
                ? 'Eine geänderte Menge wird als Zählung protokolliert.'
                : null
            "
            :error="form.errors.quantity"
          />
          <div>
            <UiSelect
              v-model="form.unit"
              label="Einheit"
              :options="units"
              :placeholder="null"
            />
            <p v-if="form.errors.unit" class="mt-1 text-sm text-red-600">
              {{ form.errors.unit }}
            </p>
          </div>
        </template>
        <UiSelect
          v-if="!item"
          v-model="form.condition_id"
          label="Zustand"
          :options="conditions"
          :placeholder="null"
        />
        <UiTextarea
          v-model="form.description"
          label="Beschreibung"
          class="sm:col-span-2"
          :error="form.errors.description"
        />
        <UiTextarea
          v-model="form.notes"
          label="Notiz"
          class="sm:col-span-2"
          :rows="2"
          :error="form.errors.notes"
        />
      </div>
    </UiCard>

    <UiCard title="Box">
      <div class="space-y-4">
        <UiCheckbox
          v-model="form.is_container"
          label="Ist eine Box"
          description="Andere Gegenstände können darin liegen und ziehen mit ihr um."
        />
        <p v-if="form.errors.is_container" class="text-sm text-red-600">
          {{ form.errors.is_container }}
        </p>
        <UiCheckbox
          v-if="form.is_container"
          v-model="form.public_contents"
          label="Inhalt öffentlich zeigen"
          description="Später zeigt der QR-Code der Box ihre Inhaltsliste ohne Anmeldung."
        />
      </div>
    </UiCard>

    <UiCard
      v-if="!item"
      title="Fotos"
      :description="`Bis zu ${maxPhotos} Fotos, am Handy direkt mit der Kamera. Das erste wird das Vorschaubild.`"
    >
      <input
        type="file"
        accept="image/jpeg,image/png,image/webp"
        multiple
        class="block w-full text-sm text-ink-muted file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-accent file:px-3 file:py-2 file:text-sm file:font-medium file:text-white"
        @change="setPhotos"
      />
      <p v-if="form.photos.length" class="mt-2 text-sm text-ink-muted">
        {{ form.photos.length }} ausgewählt
      </p>
      <p v-if="photoError" class="mt-1 text-sm text-red-600">
        {{ photoError }}
      </p>
    </UiCard>

    <UiCard title="Ort">
      <div class="grid gap-5 sm:grid-cols-2">
        <InventoryPlaceInput
          v-if="!item"
          v-model="form.place_code"
          label="Liegt in"
          :initial="place"
          :error="form.errors.place_code ?? form.errors.place"
        />
        <InventoryPlaceInput
          v-model="form.home_code"
          label="Stammplatz"
          :initial="item?.home ?? null"
          hint="Optional, nur für Dinge, die immer an denselben Platz zurück sollen."
          :error="form.errors.home_code ?? form.errors.home"
        />
      </div>
    </UiCard>

    <div class="flex justify-end">
      <UiButton type="submit" :disabled="form.processing">Speichern</UiButton>
    </div>
  </form>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { useForm } from "@inertiajs/vue3";

/**
 * Form to create or change an item. The place of an existing item changes by moving it, the
 * condition by "Zustand ändern".
 */
const props = defineProps<{
  item: {
    id: number;
    number: string;
    name: string;
    description: string | null;
    category_id: number | null;
    kind: string;
    quantity: number;
    unit: string | null;
    is_container: boolean;
    public_contents: boolean;
    notes: string | null;
    home: Place | null;
  } | null;
  // place of a new item, e.g. when created from the page of a location
  place: Place | null;
  categories: Option[];
  kinds: Option[];
  conditions: Option[];
  units: Option[];
  maxPhotos: number;
}>();

const form = useForm({
  name: props.item?.name ?? "",
  description: props.item?.description ?? "",
  category_id: props.item?.category_id ?? null,
  kind: props.item?.kind ?? "single",
  quantity: props.item?.quantity ?? 1,
  // the units come from the settings; a new item starts with the first one ("Stück")
  unit: props.item?.unit ?? props.units[0]?.value ?? null,
  is_container: props.item?.is_container ?? false,
  public_contents: props.item?.public_contents ?? true,
  notes: props.item?.notes ?? "",
  // "gut" if it exists, otherwise the first condition
  condition_id:
    (
      props.conditions.find((option) => option.label === "gut") ??
      props.conditions[0]
    )?.value ?? null,
  place_code: props.place?.code ?? "",
  home_code: props.item?.home?.code ?? "",
  photos: [] as File[],
});

// photos of a new item, scaled down in the browser; existing items get photos on their page
const { compressImages } = useImageCompression();
const setPhotos = async (event: Event) => {
  form.photos = await compressImages((event.target as HTMLInputElement).files);
};
const photoError = computed(
  () =>
    Object.entries(form.errors).find(([key]) =>
      key.startsWith("photos"),
    )?.[1] ?? null,
);

const submit = () =>
  props.item
    ? form.put(`/inventory/items/${props.item.id}`)
    : form.post("/inventory/items", { forceFormData: true });
</script>
