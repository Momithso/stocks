<template>
  <UiModal :title="item?.name ?? 'Gegenstand'" size="lg" @close="emit('close')">
    <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
    <p v-else-if="!item" class="text-sm text-ink-muted">Wird geladen …</p>
    <div v-else class="space-y-4">
      <div class="flex gap-4">
        <img
          v-if="item.photo_url"
          :src="item.photo_url"
          alt=""
          class="h-28 w-28 shrink-0 rounded-md object-cover"
        />
        <div class="min-w-0 space-y-1 text-sm">
          <p class="font-mono">{{ item.number }}</p>
          <p v-if="item.kind === 'bulk'">{{ item.quantity }} {{ item.unit }}</p>
          <p>
            <span class="text-ink-muted">Jetzt:</span>
            <InventoryPlaceLink :place="item.place" empty="kein Ort" />
          </p>
          <!-- what the page that opens the window knows besides, e.g. home and loan -->
          <slot />
          <UiBadge :variant="item.condition_is_defect ? 'danger' : 'neutral'">
            {{ item.condition }}
          </UiBadge>
        </div>
      </div>
      <template v-for="(notice, index) in item.notices" :key="index">
        <UiAlert
          v-if="notice.text"
          :type="notice.variant === 'danger' ? 'error' : notice.variant"
        >
          {{ notice.text }}
        </UiAlert>
      </template>
      <UiButton
        v-if="item.href"
        variant="secondary"
        icon="arrow-up-right-from-square"
        :href="item.href"
      >
        Öffnen
      </UiButton>
    </div>
  </UiModal>
</template>

<script setup lang="ts">
import { onMounted, ref } from "vue";

/**
 * Short information about an item by its number, the same as the card of the scan page; for
 * surfaces without the item page (kiosk).
 */
const props = defineProps<{ code: string }>();
const emit = defineEmits<{ close: [] }>();

const { url } = useSurface();

type Info = {
  name: string;
  number: string;
  kind: string;
  quantity: number;
  unit: string | null;
  place: Place | null;
  condition: string;
  condition_is_defect: boolean;
  photo_url: string | null;
  href: string | null;
  notices: ItemNotice[];
};

const item = ref<Info | null>(null);
const error = ref<string | null>(null);
onMounted(async () => {
  const response = await fetch(
    url(`/inventory/lookup?code=${encodeURIComponent(props.code)}`),
    { headers: { Accept: "application/json" } },
  );
  const data = await response.json();
  if (response.ok) {
    item.value = data;
  } else {
    error.value = data.message;
  }
});
</script>
