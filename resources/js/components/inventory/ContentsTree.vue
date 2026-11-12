<template>
  <ul
    :class="nested ? 'ml-5 border-l border-line pl-3' : 'divide-y divide-line'"
  >
    <li v-for="item in items" :key="item.id" :class="nested ? 'pt-2' : 'py-2'">
      <div class="flex items-center gap-2">
        <button
          v-if="item.contents.length"
          type="button"
          class="w-4 text-ink-muted hover:text-ink"
          :aria-label="open.has(item.id) ? 'Zuklappen' : 'Aufklappen'"
          @click="toggle(item.id)"
        >
          <FontAwesomeIcon
            :icon="[
              'fas',
              open.has(item.id) ? 'chevron-down' : 'chevron-right',
            ]"
          />
        </button>
        <span v-else class="w-4" />
        <FontAwesomeIcon
          v-if="item.is_container"
          :icon="['fas', 'box']"
          class="text-ink-muted"
        />
        <!-- the kiosk has no item pages -->
        <span v-if="kiosk">{{ item.name }}</span>
        <UiLink v-else :href="`/inventory/items/${item.id}`">{{
          item.name
        }}</UiLink>
        <span class="font-mono text-xs text-ink-muted">{{ item.number }}</span>
        <UiBadge v-if="item.kind === 'bulk'"
          >{{ item.quantity }} {{ item.unit }}</UiBadge
        >
      </div>
      <InventoryContentsTree
        v-if="item.contents.length && open.has(item.id)"
        :items="item.contents"
        nested
      />
    </li>
  </ul>
</template>

<script setup lang="ts">
import { ref, type PropType } from "vue";

/**
 * Contents of a box as tree; boxes in boxes can be opened.
 */
// the name lets the component use itself for boxes in boxes; the automatic import does not cover that
defineOptions({ name: "InventoryContentsTree" });
defineProps({
  items: { type: Array as PropType<ContentsItem[]>, required: true },
  nested: { type: Boolean, default: false },
});

const { kiosk } = useSurface();

const open = ref(new Set<number>());
const toggle = (id: number) => {
  const next = new Set(open.value);
  if (!next.delete(id)) {
    next.add(id);
  }
  open.value = next;
};
</script>
