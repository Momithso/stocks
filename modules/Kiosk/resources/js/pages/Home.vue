<template>
  <Head title="Kiosk" />
  <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
    <div v-if="person" class="flex items-center gap-4">
      <UiAvatar
        :firstname="person.firstname"
        :lastname="person.lastname"
        size="lg"
      />
      <div>
        <h1 class="text-2xl font-bold">Hallo {{ person.firstname }}!</h1>
        <p class="text-ink-muted">Was möchtest du tun?</p>
      </div>
    </div>
    <UiButton
      size="lg"
      variant="secondary"
      icon="lock"
      href="/kiosk/logout"
      method="post"
    >
      Fertig und sperren
    </UiButton>
  </div>

  <UiEmptyState
    v-if="!tiles.length"
    icon="tablet-screen-button"
    title="Noch keine Kiosk-Funktionen"
    description="Gib einer Rolle die Rechte für Inventar oder Verleih, dann erscheinen hier die Vorgänge."
  />
  <!-- one tile per task, grouped; each says what happens and how much there is to do -->
  <section v-for="section in sections" :key="section.title" class="mb-8">
    <h2
      class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted"
    >
      {{ section.title }}
    </h2>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <InertiaLink
        v-for="tile in section.tiles"
        :key="tile.href"
        :href="tile.href"
        class="flex min-h-[180px] flex-col gap-3 rounded-lg bg-surface p-6 shadow ring-1 ring-line/50 hover:ring-2 hover:ring-accent active:scale-[0.99]"
      >
        <div class="flex items-start justify-between gap-3">
          <FontAwesomeIcon
            :icon="['fas', tile.icon]"
            class="h-10 w-10 text-accent"
          />
          <UiBadge v-if="tile.badge" :variant="tile.badge.variant">
            {{ tile.badge.text }}
          </UiBadge>
        </div>
        <span class="text-xl font-semibold">{{ tile.title }}</span>
        <span v-if="tile.description" class="text-base text-ink-muted">
          {{ tile.description }}
        </span>
      </InertiaLink>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { Head, Link as InertiaLink, usePage } from "@inertiajs/vue3";

/**
 * Home page of the unlocked kiosk with the tiles of the active modules. The remaining time and
 * the lock button are in the layout.
 */
type Tile = {
  title: string;
  href: string;
  icon: string;
  description: string | null;
  section: string;
  badge: {
    text: string;
    variant: "neutral" | "accent" | "success" | "warning" | "danger";
  } | null;
};

const props = defineProps<{ tiles: Tile[] }>();

// sections in the order of their first tile
const sections = computed(() => {
  const grouped = new Map<string, Tile[]>();
  props.tiles.forEach((tile) =>
    grouped.set(tile.section, [...(grouped.get(tile.section) ?? []), tile]),
  );
  return [...grouped].map(([title, tiles]) => ({ title, tiles }));
});

const page = usePage<SharedProps>();
const person = computed(() => page.props.surface?.person ?? null);
</script>
