<template>
  <UiModal :title="title" @close="emit('close')">
    <form class="space-y-5" @submit.prevent="submit">
      <UiSelect
        v-if="parentType"
        v-model="form.parent_id"
        :label="`Liegt in (${typeLabels[parentType]})`"
        :options="parentOptions"
        :placeholder="null"
      />
      <p v-if="form.errors.parent_id" class="text-sm text-red-600">
        {{ form.errors.parent_id }}
      </p>
      <UiInput
        v-model="form.code"
        label="Kürzel"
        required
        :hint="codeHints[type]"
        :error="form.errors.code"
      />
      <UiInput v-model="form.name" label="Name" :error="form.errors.name" />
      <UiTextarea
        v-model="form.description"
        label="Beschreibung"
        :rows="2"
        :error="form.errors.description"
      />
      <UiAlert v-if="deleteError" type="error" :message="deleteError" />
      <div class="flex flex-col gap-3 sm:flex-row">
        <UiButton type="submit" class="flex-1" :disabled="form.processing">
          Speichern
        </UiButton>
        <UiButton
          v-if="location"
          variant="danger"
          icon="trash"
          @click="destroy"
        >
          Löschen
        </UiButton>
      </div>
    </form>
  </UiModal>
</template>

<script setup lang="ts">
import { computed, ref, type PropType } from "vue";
import { router, useForm } from "@inertiajs/vue3";

type LocationType = "address" | "room" | "shelf" | "compartment";
type LocationOption = {
  id: number;
  type: LocationType;
  full_code: string;
  name: string | null;
};

/**
 * Create or change a location. The level follows from the parent; the server checks it.
 */
const props = defineProps({
  // location to change, null for a new one
  location: {
    type: Object as PropType<
      | (LocationOption & {
          parent_id: number | null;
          code: string;
          description: string | null;
        })
      | null
    >,
    default: null,
  },
  parentId: { type: Number as PropType<number | null>, default: null },
  // all locations, for the choice of the parent
  locations: { type: Array as PropType<LocationOption[]>, required: true },
});

const emit = defineEmits<{ close: [] }>();

const levels: LocationType[] = ["address", "room", "shelf", "compartment"];
const typeLabels = {
  address: "Adresse",
  room: "Raum",
  shelf: "Lagerplatz",
  compartment: "Fach",
};
const codeHints = {
  address: "Kurz für das Gebäude, z. B. EU",
  room: "Raumnummer wie an der Tür, z. B. 014",
  shelf: "Art und Nummer: R03 für Regal, S01 für Schrank, B01 für Bodenplatz",
  compartment: "Regalboden von unten gezählt, z. B. 4",
};

const parent = props.locations.find(
  (location) => location.id === props.parentId,
);
const type: LocationType =
  props.location?.type ??
  (parent ? levels[levels.indexOf(parent.type) + 1] : "address");
const parentType = levels[levels.indexOf(type) - 1] as LocationType | undefined;

const parentOptions = computed(() =>
  props.locations
    .filter((location) => location.type === parentType)
    .map((location) => ({
      value: location.id,
      label: [location.full_code, location.name].filter(Boolean).join(" · "),
    })),
);

const title = props.location
  ? `${props.location.full_code} bearbeiten`
  : `${typeLabels[type]} anlegen`;

const form = useForm({
  parent_id: props.parentId,
  code: props.location?.code ?? "",
  name: props.location?.name ?? "",
  description: props.location?.description ?? "",
});

const submit = () => {
  const options = { preserveScroll: true, onSuccess: () => emit("close") };
  if (props.location) {
    form.put(`/inventory/locations/${props.location.id}`, options);
  } else {
    form.post("/inventory/locations", options);
  }
};

const deleteError = ref<string | null>(null);
const destroy = () =>
  router.delete(`/inventory/locations/${props.location!.id}`, {
    onError: (errors) => (deleteError.value = errors.location ?? null),
  });
</script>
