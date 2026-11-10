<template>
  <UiPageHeader
    title="Einheiten"
    description="Einheiten für Mengenartikel. Umbenennen ändert auch die Gegenstände mit dieser Einheit."
  >
    <UiButton icon="plus" @click="open(null)">Einheit anlegen</UiButton>
  </UiPageHeader>

  <UiAlert v-if="page.props.errors?.unit" type="error" class="mb-4">
    {{ page.props.errors.unit }}
  </UiAlert>

  <UiCard flush>
    <ul class="divide-y divide-line">
      <li
        v-for="unit in units"
        :key="unit.id"
        class="flex flex-wrap items-center gap-2 px-4 py-3 sm:px-6"
      >
        <span class="font-medium">{{ unit.name }}</span>
        <UiBadge v-if="unit.is_consumable" variant="accent">
          Verbrauchsmaterial
        </UiBadge>
        <span class="text-sm text-ink-muted">
          {{ unit.items_count }}
          {{ unit.items_count === 1 ? "Gegenstand" : "Gegenstände" }}
        </span>
        <UiButton
          variant="ghost"
          size="sm"
          icon="pen"
          class="ml-auto"
          @click="open(unit)"
        >
          <span class="sr-only">Bearbeiten</span>
        </UiButton>
      </li>
    </ul>
  </UiCard>

  <UiModal
    v-if="editing"
    :title="editing.id ? 'Einheit bearbeiten' : 'Einheit anlegen'"
    @close="editing = null"
  >
    <form class="space-y-5" @submit.prevent="submit">
      <UiInput
        v-model="form.name"
        label="Name"
        required
        :error="form.errors.name"
      />
      <UiCheckbox
        v-model="form.is_consumable"
        label="Verbrauchsmaterial"
        description="Gegenstände mit dieser Einheit bekommen den Knopf „Verbraucht“, z. B. Klebeband oder Kabelbinder."
      />
      <div class="flex flex-col gap-3 sm:flex-row">
        <UiButton type="submit" class="flex-1" :disabled="form.processing">
          Speichern
        </UiButton>
        <UiButton
          v-if="editing.id"
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
import { ref } from "vue";
import { router, useForm, usePage } from "@inertiajs/vue3";

type Unit = {
  id: number;
  name: string;
  is_consumable: boolean;
  items_count: number;
};

/**
 * The list of units. A unit can only be deleted when no item has it.
 */
defineProps<{ units: Unit[] }>();

const page = usePage<SharedProps>();

const editing = ref<{ id: number | null } | null>(null);
const form = useForm({ name: "", is_consumable: false });

const open = (unit: Unit | null) => {
  form.defaults({
    name: unit?.name ?? "",
    is_consumable: unit?.is_consumable ?? false,
  });
  form.reset();
  form.clearErrors();
  editing.value = { id: unit?.id ?? null };
};

const submit = () => {
  const options = {
    preserveScroll: true,
    onSuccess: () => (editing.value = null),
  };
  if (editing.value?.id) {
    form.put(`/inventory/units/${editing.value.id}`, options);
  } else {
    form.post("/inventory/units", options);
  }
};

const destroy = () =>
  router.delete(`/inventory/units/${editing.value!.id}`, {
    preserveScroll: true,
    onFinish: () => (editing.value = null),
  });
</script>
