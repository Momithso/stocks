<template>
  <UiPageHeader
    title="Etiketten"
    description="Vorlagen für Etiketten. Die Daten kommen beim Drucken aus dem Gegenstand oder Standort; die Standardvorlage einer Art ist beim Drucken vorausgewählt."
  >
    <UiButton
      v-if="demoSheet"
      variant="secondary"
      icon="file-pdf"
      href="/labels/demo-sheet.pdf"
      external
    >
      Testetiketten (Demo)
    </UiButton>
    <UiButton icon="plus" @click="createOpen = true">Vorlage anlegen</UiButton>
  </UiPageHeader>

  <div class="space-y-6">
    <UiCard
      v-for="target in targets"
      :key="target.value"
      :title="target.label"
      flush
    >
      <ul v-if="byTarget(target.value).length" class="divide-y divide-line">
        <li
          v-for="template in byTarget(target.value)"
          :key="template.id"
          class="flex flex-wrap items-center gap-2 px-4 py-3 sm:px-6"
        >
          <InertiaLink
            :href="`/labels/templates/${template.id}/edit`"
            class="font-medium hover:text-accent"
          >
            {{ template.name }}
          </InertiaLink>
          <UiBadge v-if="template.is_default" variant="accent"
            >Standard</UiBadge
          >
          <span class="text-sm text-ink-muted">
            {{ template.width_mm }} × {{ template.height_mm }} mm ·
            {{ template.elements.length }} Elemente
          </span>
          <span class="ml-auto flex gap-1">
            <UiButton
              variant="ghost"
              size="sm"
              icon="pen"
              :href="`/labels/templates/${template.id}/edit`"
            >
              <span class="sr-only">Bearbeiten</span>
            </UiButton>
            <UiButton
              variant="ghost"
              size="sm"
              icon="copy"
              :href="`/labels/templates/${template.id}/duplicate`"
              method="post"
            >
              <span class="sr-only">Duplizieren</span>
            </UiButton>
            <UiButton
              variant="ghost"
              size="sm"
              icon="trash"
              @click="deleting = template"
            >
              <span class="sr-only">Löschen</span>
            </UiButton>
          </span>
        </li>
      </ul>
      <UiEmptyState v-else icon="tag" title="Noch keine Vorlage" />
    </UiCard>
  </div>

  <UiModal
    v-if="createOpen"
    title="Vorlage anlegen"
    @close="createOpen = false"
  >
    <form class="space-y-5" @submit.prevent="create">
      <UiInput
        v-model="form.name"
        label="Name"
        required
        :error="form.errors.name"
      />
      <UiSelect
        v-model="form.target"
        label="Art"
        :options="targets"
        :placeholder="null"
      />
      <UiSelect
        v-model="size"
        label="Größe"
        :options="sizeOptions"
        :placeholder="null"
        hint="Etikettengrößen der Drucker, oder frei in Millimetern."
      />
      <div v-if="size === 'custom'" class="grid grid-cols-2 gap-3">
        <UiInput
          v-model="form.width_mm"
          label="Breite (mm)"
          type="number"
          step="0.5"
          :error="form.errors.width_mm"
        />
        <UiInput
          v-model="form.height_mm"
          label="Höhe (mm)"
          type="number"
          step="0.5"
          :error="form.errors.height_mm"
        />
      </div>
      <UiButton type="submit" class="w-full" :disabled="form.processing">
        Anlegen und gestalten
      </UiButton>
    </form>
  </UiModal>

  <UiConfirmModal
    v-if="deleting"
    title="Vorlage löschen"
    :message="`„${deleting.name}“ wird gelöscht. Gedruckte Etiketten bleiben gültig.`"
    @close="deleting = null"
    @confirm="destroy"
  />
</template>

<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { Link as InertiaLink, router, useForm } from "@inertiajs/vue3";

/**
 * Label templates by target, with creating, copying and deleting. The editor is its own page.
 */
const props = defineProps<{
  templates: LabelTemplate[];
  targets: { value: LabelTargetName; label: string }[];
  sizes: { label: string; width: number; height: number }[];
  // instance with demo data: the test label sheet can be downloaded
  demoSheet: boolean;
}>();

const byTarget = (target: string) =>
  props.templates.filter((template) => template.target === target);

const createOpen = ref(false);
const form = useForm({
  name: "",
  target: "item" as LabelTargetName,
  width_mm: 50 as number | string,
  height_mm: 30 as number | string,
});

const sizeOptions = computed(() => [
  ...props.sizes.map((entry, index) => ({
    value: String(index),
    label: entry.label,
  })),
  { value: "custom", label: "Eigene Größe" },
]);
const size = ref(props.sizes.length ? "0" : "custom");
watch(size, (value) => {
  const chosen = props.sizes[Number(value)];
  if (value !== "custom" && chosen) {
    form.width_mm = chosen.width;
    form.height_mm = chosen.height;
  }
});

const create = () => form.post("/labels/templates");

const deleting = ref<LabelTemplate | null>(null);
const destroy = () =>
  router.delete(`/labels/templates/${deleting.value!.id}`, {
    preserveScroll: true,
    onFinish: () => (deleting.value = null),
  });
</script>
