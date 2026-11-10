<template>
  <ul
    :class="depth ? 'ml-6 border-l border-line pl-3' : 'divide-y divide-line'"
  >
    <li v-for="item in items" :key="item.number" class="py-2">
      <label class="flex cursor-pointer items-center gap-3">
        <input
          type="checkbox"
          class="h-6 w-6 shrink-0 rounded border-line text-accent focus:ring-accent"
          :checked="checked.includes(item.number)"
          @change="$emit('toggle', item.number)"
        />
        <img
          v-if="item.photo_url"
          :src="item.photo_url"
          alt=""
          class="h-12 w-12 shrink-0 rounded object-cover"
        />
        <span class="min-w-0 flex-1">
          <span
            :class="[
              'block font-medium',
              checked.includes(item.number)
                ? 'text-ink-muted line-through'
                : '',
            ]"
          >
            {{ item.name }}
          </span>
          <span class="block font-mono text-xs text-ink-muted">{{
            item.number
          }}</span>
        </span>
        <span class="shrink-0 text-sm font-semibold">
          {{
            item.kind === "bulk" ? `${item.quantity} ${item.unit ?? ""}` : "1"
          }}
        </span>
      </label>
      <LabelsPublicContents
        v-if="item.contents.length"
        :items="item.contents"
        :checked="checked"
        :depth="depth + 1"
        class="mt-2"
        @toggle="$emit('toggle', $event)"
      />
    </li>
  </ul>
</template>

<script setup lang="ts">
export type PublicItem = {
  number: string;
  name: string;
  quantity: number;
  unit: string | null;
  kind: "single" | "bulk";
  is_container: boolean;
  photo_url: string | null;
  contents: PublicItem[];
};

/**
 * Contents of a box in the public view, every row can be ticked off (only on this device).
 */
withDefaults(
  defineProps<{ items: PublicItem[]; checked: string[]; depth?: number }>(),
  { depth: 0 },
);
defineEmits<{ toggle: [number: string] }>();
</script>
