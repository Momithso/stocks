<template>
  <UiPageHeader
    title="Zustände"
    description="Die Zustände, die ein Gegenstand haben kann. Der Verlauf behält alte Namen, auch nach Umbenennen oder Löschen."
  >
    <UiButton icon="plus" @click="open(null)">Zustand anlegen</UiButton>
  </UiPageHeader>

  <UiAlert v-if="page.props.errors?.condition" type="error" class="mb-4">
    {{ page.props.errors.condition }}
  </UiAlert>

  <UiCard flush>
    <ul class="divide-y divide-line">
      <li
        v-for="condition in conditions"
        :key="condition.id"
        class="flex flex-wrap items-center gap-2 px-4 py-3 sm:px-6"
      >
        <UiBadge :variant="condition.is_defect ? 'danger' : 'neutral'">
          {{ condition.name }}
        </UiBadge>
        <span class="text-sm text-ink-muted">
          {{ condition.items_count }}
          {{ condition.items_count === 1 ? "Gegenstand" : "Gegenstände" }}
        </span>
        <UiButton
          variant="ghost"
          size="sm"
          icon="pen"
          class="ml-auto"
          @click="open(condition)"
        >
          <span class="sr-only">Bearbeiten</span>
        </UiButton>
      </li>
    </ul>
  </UiCard>

  <UiModal
    v-if="editing"
    :title="editing.id ? 'Zustand bearbeiten' : 'Zustand anlegen'"
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
        v-model="form.is_defect"
        label="Bedeutet einen Schaden"
        description="Wird rot hervorgehoben."
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

type Condition = {
  id: number;
  name: string;
  is_defect: boolean;
  items_count: number;
};

/**
 * The list of conditions. A condition can only be deleted when no item has it.
 */
defineProps<{ conditions: Condition[] }>();

const page = usePage<SharedProps>();

const editing = ref<{ id: number | null } | null>(null);
const form = useForm({ name: "", is_defect: false });

const open = (condition: Condition | null) => {
  form.defaults({
    name: condition?.name ?? "",
    is_defect: condition?.is_defect ?? false,
  });
  form.reset();
  form.clearErrors();
  editing.value = { id: condition?.id ?? null };
};

const submit = () => {
  const options = {
    preserveScroll: true,
    onSuccess: () => (editing.value = null),
  };
  if (editing.value?.id) {
    form.put(`/inventory/conditions/${editing.value.id}`, options);
  } else {
    form.post("/inventory/conditions", options);
  }
};

const destroy = () =>
  router.delete(`/inventory/conditions/${editing.value!.id}`, {
    preserveScroll: true,
    onFinish: () => (editing.value = null),
  });
</script>
