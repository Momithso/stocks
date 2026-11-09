<template>
  <UiPageHeader
    title="Module"
    description="Module erweitern den Kern. Ausgeschaltete Module behalten ihre Daten."
  />

  <UiAlert
    v-if="page.props.errors.module"
    type="error"
    :message="page.props.errors.module"
    class="mb-6"
  />

  <UiCard flush>
    <UiEmptyState
      v-if="!modules.length"
      icon="puzzle-piece"
      title="Keine Module vorhanden"
    />
    <ul v-else class="divide-y divide-line">
      <li
        v-for="module in modules"
        :key="module.key"
        class="flex flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-6"
      >
        <div>
          <p class="flex items-center gap-2 font-medium">
            {{ module.name }}
            <UiBadge :variant="module.active ? 'success' : 'neutral'">
              {{ module.active ? "an" : "aus" }}
            </UiBadge>
            <span class="text-xs text-ink-muted">v{{ module.version }}</span>
          </p>
          <p class="text-sm text-ink-muted">{{ module.description }}</p>
          <p v-if="module.requires.length" class="text-sm text-ink-muted">
            Braucht: {{ module.requires.join(", ") }}
          </p>
        </div>
        <UiButton
          :variant="module.active ? 'secondary' : 'primary'"
          size="sm"
          @click="toggle(module.key, !module.active)"
        >
          {{ module.active ? "Ausschalten" : "Einschalten" }}
        </UiButton>
      </li>
    </ul>
  </UiCard>
</template>

<script setup lang="ts">
import { router, usePage } from "@inertiajs/vue3";

defineProps<{
  modules: {
    key: string;
    name: string;
    description: string;
    version: string;
    requires: string[];
    active: boolean;
  }[];
}>();

const page = usePage<SharedProps>();

const toggle = (key: string, active: boolean) =>
  router.put(`/admin/modules/${key}`, { active }, { preserveScroll: true });
</script>
