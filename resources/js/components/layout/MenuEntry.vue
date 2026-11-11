<template>
  <!-- group: a button that opens its entries below -->
  <div v-if="entry.children">
    <button
      type="button"
      :title="collapsed ? entry.title : undefined"
      :aria-expanded="open"
      :class="[rowClass, 'w-full text-ink hover:bg-surface-muted']"
      @click="open = !open"
    >
      <FontAwesomeIcon :icon="['fas', entry.icon]" class="w-4" />
      <template v-if="!collapsed">
        <span class="flex-1 text-left">{{ entry.title }}</span>
        <!-- a problem of an entry inside shows while the group is closed -->
        <span
          v-if="
            !open &&
            entry.children.some((child) => child.badge?.variant === 'danger')
          "
          class="h-2 w-2 rounded-full bg-red-600"
          aria-label="Problem in dieser Gruppe"
        />
        <FontAwesomeIcon
          :icon="['fas', open ? 'chevron-down' : 'chevron-right']"
          class="text-xs text-ink-muted"
        />
      </template>
    </button>
    <ul
      v-if="open"
      :class="[
        'mt-1 space-y-1',
        collapsed ? '' : 'ml-4 border-l border-line pl-2',
      ]"
    >
      <li v-for="child in entry.children" :key="child.href!">
        <!-- the component itself, by its file name -->
        <MenuEntry
          :entry="child"
          :collapsed="collapsed"
          :large="large"
          @navigate="emit('navigate')"
        />
      </li>
    </ul>
  </div>

  <InertiaLink
    v-else
    :href="entry.href!"
    :title="collapsed ? entry.title : undefined"
    :aria-current="isActive(entry) ? 'page' : undefined"
    :class="[
      rowClass,
      isActive(entry)
        ? 'bg-accent/10 text-accent'
        : 'text-ink hover:bg-surface-muted',
    ]"
    @click="emit('navigate')"
  >
    <FontAwesomeIcon :icon="['fas', entry.icon]" class="w-4" />
    <span v-if="!collapsed" class="flex-1">{{ entry.title }}</span>
    <!-- short status of the entry, e.g. of the mail server -->
    <UiBadge v-if="entry.badge && !collapsed" :variant="entry.badge.variant">
      {{ entry.badge.text }}
    </UiBadge>
  </InertiaLink>
</template>

<script setup lang="ts">
import { computed, ref, type PropType } from "vue";
import { Link as InertiaLink } from "@inertiajs/vue3";

/**
 * One entry of the menu: a link, or a collapsible group of links (App\Support\Extensions\Navigation::addGroup).
 * Used by the sidebar and by the full menu on phones.
 */
const props = defineProps({
  entry: { type: Object as PropType<NavigationEntry>, required: true },
  // sidebar shows icons only
  collapsed: { type: Boolean, default: false },
  // rows of at least 44 pixels for touch screens
  large: { type: Boolean, default: false },
});

const emit = defineEmits<{ navigate: [] }>();

// the entry with the longest matching address is active
const { isActive } = useActiveEntry();

// a group starts open while one of its pages is shown
const open = ref(props.entry.children?.some(isActive) ?? false);

const rowClass = computed(() => [
  "flex items-center gap-3 rounded-md px-3 text-sm font-medium",
  props.large ? "min-h-[44px]" : "py-2",
]);
</script>
