<template>
  <UiModal title="Zustand ändern" @close="$emit('close')">
    <form class="space-y-5" @submit.prevent="submit()">
      <UiSelect
        v-model="form.condition_id"
        label="Neuer Zustand"
        :options="conditions"
        :placeholder="null"
      />
      <UiTextarea
        v-model="form.reason"
        label="Warum?"
        required
        placeholder="z. B. Gehäuse beim Abbau gebrochen"
        :error="form.errors.reason ?? form.errors.condition_id"
      />
      <UiPhotoPicker
        v-model="form.photos"
        label="Fotos (empfohlen)"
        :error="photoError"
      />
      <InventoryReservedConfirm
        :message="form.errors.confirm"
        :processing="form.processing"
        @confirm="submit(true)"
      />
      <UiButton type="submit" class="w-full" :disabled="form.processing"
        >Speichern</UiButton
      >
    </form>
  </UiModal>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { useForm } from "@inertiajs/vue3";

/**
 * Change the condition of an item with a reason and photos. Used on the item page and on the scan
 * page.
 */
const props = defineProps<{
  item: { id: number; condition_id: number };
  conditions: Option[];
}>();
const emit = defineEmits<{ close: []; done: [] }>();
const { url } = useSurface();

const form = useForm({
  // preselect another condition than the current one
  condition_id:
    props.conditions.find((option) => option.value !== props.item.condition_id)
      ?.value ?? null,
  reason: "",
  photos: [] as File[],
});
const photoError = computed(
  () =>
    Object.entries(form.errors).find(([key]) =>
      key.startsWith("photos"),
    )?.[1] ?? null,
);
// confirmed: asked before and answered "Ja, trotzdem" (the item is booked)
const submit = (confirmed = false) =>
  form
    .transform((data) => ({ ...data, confirmed }))
    .post(url(`/inventory/items/${props.item.id}/condition`), {
      preserveScroll: true,
      preserveState: true,
      forceFormData: true,
      onSuccess: () => emit("done"),
    });
</script>
