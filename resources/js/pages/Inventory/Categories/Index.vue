<template>
  <UiPageHeader
    title="Kategorien"
    description="Kategorien mit einer Ebene Unterkategorien."
  >
    <UiButton icon="plus" @click="open(null, null)">Kategorie anlegen</UiButton>
  </UiPageHeader>

  <UiAlert v-if="page.props.errors?.category" type="error" class="mb-4">
    {{ page.props.errors.category }}
  </UiAlert>

  <UiCard flush>
    <UiEmptyState
      v-if="!categories.length"
      icon="tags"
      title="Noch keine Kategorien"
    />
    <ul v-else class="divide-y divide-line">
      <li
        v-for="category in categories"
        :key="category.id"
        class="px-4 py-3 sm:px-6"
      >
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-medium">{{ category.name }}</span>
          <UiBadge v-if="category.items_count">{{
            category.items_count
          }}</UiBadge>
          <div class="ml-auto flex gap-2">
            <UiButton
              variant="ghost"
              size="sm"
              icon="plus"
              @click="open(null, category.id)"
            >
              Unterkategorie
            </UiButton>
            <UiButton
              variant="ghost"
              size="sm"
              icon="pen"
              @click="open(category, null)"
            >
              <span class="sr-only">Bearbeiten</span>
            </UiButton>
          </div>
        </div>
        <ul v-if="category.children.length" class="ml-6 mt-2 space-y-1">
          <li
            v-for="child in category.children"
            :key="child.id"
            class="flex items-center gap-2 text-sm"
          >
            <span>{{ child.name }}</span>
            <UiBadge v-if="child.items_count">{{ child.items_count }}</UiBadge>
            <UiButton
              variant="ghost"
              size="sm"
              icon="pen"
              class="ml-auto"
              @click="open(child, null)"
            >
              <span class="sr-only">Bearbeiten</span>
            </UiButton>
          </li>
        </ul>
      </li>
    </ul>
  </UiCard>

  <UiModal
    v-if="editing"
    :title="editing.id ? 'Kategorie bearbeiten' : 'Kategorie anlegen'"
    @close="editing = null"
  >
    <form class="space-y-5" @submit.prevent="submit">
      <UiInput
        v-model="form.name"
        label="Name"
        required
        :error="form.errors.name"
      />
      <UiSelect
        v-model="form.parent_id"
        label="Oberkategorie"
        :options="parentOptions"
        placeholder="keine"
      />
      <p v-if="form.errors.parent_id" class="text-sm text-red-600">
        {{ form.errors.parent_id }}
      </p>
      <div class="flex flex-col gap-3 sm:flex-row">
        <UiButton type="submit" class="flex-1" :disabled="form.processing"
          >Speichern</UiButton
        >
        <UiButton
          v-if="editing.id"
          variant="danger"
          icon="trash"
          @click="destroy"
          >Löschen</UiButton
        >
      </div>
    </form>
  </UiModal>
</template>

<script setup lang="ts">
import { computed, ref } from "vue";
import { router, useForm, usePage } from "@inertiajs/vue3";

type CategoryRow = {
  id: number;
  name: string;
  parent_id?: number | null;
  items_count: number;
};

/**
 * Categories and their sub categories.
 */
const props = defineProps<{
  categories: (CategoryRow & { children: CategoryRow[] })[];
}>();

const page = usePage<SharedProps>();

const editing = ref<{ id: number | null } | null>(null);
const form = useForm({ name: "", parent_id: null as number | null });

// only top level categories can be a parent
const parentOptions = computed(() =>
  props.categories
    .filter((category) => category.id !== editing.value?.id)
    .map((category) => ({ value: category.id, label: category.name })),
);

const open = (category: CategoryRow | null, parentId: number | null) => {
  form.defaults({
    name: category?.name ?? "",
    parent_id: category?.parent_id ?? parentId,
  });
  form.reset();
  form.clearErrors();
  editing.value = { id: category?.id ?? null };
};

const submit = () => {
  const options = {
    preserveScroll: true,
    onSuccess: () => (editing.value = null),
  };
  if (editing.value?.id) {
    form.put(`/inventory/categories/${editing.value.id}`, options);
  } else {
    form.post("/inventory/categories", options);
  }
};

const destroy = () =>
  router.delete(`/inventory/categories/${editing.value!.id}`, {
    preserveScroll: true,
    onFinish: () => (editing.value = null),
  });
</script>
