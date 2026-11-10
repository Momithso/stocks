<template>
  <div>
    <UiInput
      v-model="model"
      :label="label"
      :error="error"
      :hint="selected ? selectedText : hint"
      :required="required"
      :placeholder="placeholder"
      autocomplete="off"
      @update:model-value="search"
    />
    <ul
      v-if="suggestions.length"
      class="mt-1 max-h-64 w-full overflow-auto rounded-md bg-surface py-1 text-sm shadow-lg ring-1 ring-line"
    >
      <li v-for="place in suggestions" :key="`${place.type}-${place.id}`">
        <button
          type="button"
          class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-surface-muted"
          @click="choose(place)"
        >
          <FontAwesomeIcon
            :icon="['fas', place.type === 'location' ? 'location-dot' : 'box']"
            class="text-ink-muted"
          />
          <span class="font-mono font-semibold">{{ place.code }}</span>
          <span class="truncate text-ink-muted">{{ place.name }}</span>
        </button>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, type PropType } from "vue";

/**
 * Field for a place: the code of a location or the number of a box.
 *
 * The suggestions are part of the normal flow (not floating), so a dialog grows with them instead
 * of cutting them off. Typing shows matching
 * places; a hand scanner can type the code directly. The server resolves the code.
 */
const props = defineProps({
  label: { type: String, required: true },
  error: { type: String, default: null },
  hint: { type: String, default: "Kennziffer, Name oder Boxnummer" },
  required: { type: Boolean, default: false },
  placeholder: { type: String, default: "z. B. EU-014-R03.4" },
  // place the field starts with
  initial: { type: Object as PropType<Place | null>, default: null },
});

const model = defineModel<string>({ default: "" });
const selected = ref<Place | null>(props.initial);
const suggestions = ref<Place[]>([]);

const selectedText = computed(() =>
  [
    selected.value?.type === "location" ? "Standort" : "Box",
    selected.value?.name,
  ]
    .filter(Boolean)
    .join(": "),
);

let timeout: ReturnType<typeof setTimeout> | null = null;
const search = (value: string | number | null | undefined) => {
  selected.value = null;
  if (timeout) {
    clearTimeout(timeout);
  }
  const text = String(value ?? "").trim();
  if (!text) {
    suggestions.value = [];
    return;
  }

  timeout = setTimeout(async () => {
    const response = await fetch(
      `/inventory/places?search=${encodeURIComponent(text)}`,
      { headers: { Accept: "application/json" } },
    );
    suggestions.value = response.ok ? await response.json() : [];
  }, 250);
};

const choose = (place: Place) => {
  model.value = place.code;
  selected.value = place;
  suggestions.value = [];
};
</script>
