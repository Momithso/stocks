<template>
  <UiModal title="Verbraucht" @close="$emit('close')">
    <form class="space-y-5" @submit.prevent="submit()">
      <UiInput
        v-model="form.quantity"
        label="Wie viel wurde verbraucht?"
        type="number"
        inputmode="numeric"
        required
        :hint="`Noch da: ${item.quantity} ${item.unit ?? ''}`"
        :error="form.errors.quantity"
      />
      <UiInput
        v-model="form.note"
        label="Wofür? (optional)"
        :error="form.errors.note"
      />
      <InventoryReservedConfirm
        :message="form.errors.confirm"
        :processing="form.processing"
        @confirm="submit(true)"
      />
      <UiButton type="submit" class="w-full" :disabled="form.processing">
        Als verbraucht ausbuchen
      </UiButton>
    </form>
  </UiModal>
</template>

<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";

/**
 * Mark a part of a consumable as used up. Used on the item page and on the scan page.
 */
const props = defineProps<{
  item: { id: number; quantity: number; unit: string | null };
}>();
const emit = defineEmits<{ close: []; done: [] }>();
const { url } = useSurface();

const form = useForm({ quantity: 1 as number | null, note: "" });
// confirmed: asked before and answered "Ja, trotzdem" (the item is booked)
const submit = (confirmed = false) =>
  form
    .transform((data) => ({ ...data, confirmed }))
    .post(url(`/inventory/items/${props.item.id}/consume`), {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => emit("done"),
    });
</script>
