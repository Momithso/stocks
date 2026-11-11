<template>
  <Head>
    <title>{{ title }}</title>
    <meta name="robots" content="noindex, nofollow" />
  </Head>

  <div class="space-y-6">
    <!-- item or box -->
    <section
      v-if="item && (kind === 'item' || kind === 'box')"
      class="overflow-hidden rounded-lg bg-surface shadow ring-1 ring-line/50"
    >
      <img
        v-if="item.photo_url"
        :src="item.photo_url"
        alt=""
        class="aspect-[4/3] w-full object-cover"
      />
      <div class="space-y-2 p-5">
        <h1 class="text-2xl font-bold tracking-tight">{{ item.name }}</h1>
        <p class="font-mono text-ink-muted">{{ item.number }}</p>
        <p class="pt-2 text-lg">
          Eigentum von <strong>{{ owner }}</strong>
        </p>
        <p v-if="contact" class="text-sm">
          Gefunden? Bitte melde dich bei
          <a
            :href="`mailto:${contact}?subject=${encodeURIComponent(item.number)}`"
            class="font-medium text-accent underline"
            >{{ contact }}</a
          >.
        </p>
      </div>
    </section>

    <!-- contents of a box to tick off while packing -->
    <section
      v-if="kind === 'box'"
      class="rounded-lg bg-surface shadow ring-1 ring-line/50"
    >
      <header
        class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-3"
      >
        <h2 class="font-semibold">Inhalt</h2>
        <span class="text-sm text-ink-muted">
          {{ checked.length }} von {{ total }} abgehakt
          <button
            v-if="checked.length"
            type="button"
            class="ml-2 underline"
            @click="checked = []"
          >
            zurücksetzen
          </button>
        </span>
      </header>
      <div class="px-5">
        <LabelsPublicContents
          v-if="contents.length"
          :items="contents"
          :checked="checked"
          @toggle="toggle"
        />
        <p v-else class="py-4 text-sm text-ink-muted">Die Box ist leer.</p>
      </div>
      <p class="border-t border-line px-5 py-3 text-xs text-ink-muted">
        Abgehakt wird nur auf diesem Gerät, in Stocks ändert sich nichts.
      </p>
    </section>

    <!-- sections of modules, e.g. the pack pin of a loan -->
    <component
      :is="section.is"
      v-for="section in sectionComponents"
      :key="section.component"
      v-bind="section.props"
      :checked="checked"
      :total="total"
    />

    <!-- location -->
    <section
      v-if="kind === 'location' && location"
      class="rounded-lg bg-surface p-5 shadow ring-1 ring-line/50"
    >
      <h1 class="font-mono text-2xl font-bold">{{ location.code }}</h1>
      <p class="pt-2 text-lg">
        Lagerplatz von <strong>{{ owner }}</strong>
      </p>
    </section>

    <!-- retired item, reserved number, unknown code -->
    <section
      v-if="kind === 'retired' && item"
      class="rounded-lg bg-surface p-5 shadow ring-1 ring-line/50"
    >
      <h1 class="text-xl font-bold">{{ item.name }}</h1>
      <p class="font-mono text-ink-muted">{{ item.number }}</p>
      <p class="pt-2 text-lg">Nicht mehr im Bestand.</p>
    </section>
    <section
      v-if="kind === 'reserved' && item"
      class="rounded-lg bg-surface p-5 shadow ring-1 ring-line/50"
    >
      <p class="font-mono text-ink-muted">{{ item.number }}</p>
      <p class="pt-2 text-lg">
        Eigentum von <strong>{{ owner }}</strong>
      </p>
    </section>
    <section
      v-if="kind === 'unknown'"
      class="rounded-lg bg-surface p-5 shadow ring-1 ring-line/50"
    >
      <h1 class="text-xl font-bold">Unbekannter Code</h1>
      <p class="pt-2">{{ message }}</p>
      <UiButton v-if="createUrl" class="mt-4" icon="plus" :href="createUrl"
        >Gegenstand anlegen</UiButton
      >
    </section>

    <UiButton
      v-if="!loggedIn"
      variant="secondary"
      icon="right-to-bracket"
      class="w-full"
      :href="`/s/${encodeURIComponent(code)}?login=1`"
    >
      Anmelden
    </UiButton>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { Head } from "@inertiajs/vue3";
import type { PublicItem } from "../../components/labels/PublicContents.vue";

/**
 * Public view of a scanned label, without login: only what helps to bring an item back or to
 * pack a box. Place, borrower and history stay hidden.
 */
const props = defineProps<{
  code: string;
  kind: "item" | "box" | "location" | "retired" | "reserved" | "unknown";
  owner: string;
  contact: string | null;
  loggedIn: boolean;
  item?: { number: string; name?: string; photo_url?: string | null };
  location?: { code: string; name: string | null };
  contents?: PublicItem[];
  message?: string;
  createUrl?: string | null;
  sections?: { component: string; props: Record<string, unknown> }[];
}>();

const contents = computed(() => props.contents ?? []);
const sectionComponents = computed(() =>
  (props.sections ?? []).map((section) => ({
    ...section,
    is: useModuleComponent(section.component),
  })),
);
const title = computed(
  () =>
    props.item?.name ??
    props.item?.number ??
    props.location?.code ??
    props.code,
);

const count = (items: PublicItem[]): number =>
  items.reduce((sum, item) => sum + 1 + count(item.contents), 0);
const total = computed(() => count(contents.value));

// ticked rows are kept in this browser per box
const storageKey = `stocks.checklist.${props.item?.number ?? props.code}`;
const read = (): string[] => {
  try {
    return JSON.parse(localStorage.getItem(storageKey) ?? "[]");
  } catch {
    return [];
  }
};
const checked = ref<string[]>(read());
watch(checked, (value) => {
  try {
    localStorage.setItem(storageKey, JSON.stringify(value));
  } catch {
    // private mode: not remembered
  }
});
const toggle = (number: string) => {
  checked.value = checked.value.includes(number)
    ? checked.value.filter((entry) => entry !== number)
    : [...checked.value, number];
};
</script>
