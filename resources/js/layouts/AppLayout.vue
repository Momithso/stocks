<template>
  <div class="min-h-screen">
    <!-- sidebar (pc) -->
    <aside
      :class="[
        'fixed inset-y-0 left-0 z-20 hidden flex-col border-r border-line bg-surface lg:flex',
        collapsed ? 'w-16' : 'w-64',
      ]"
    >
      <!-- room for a logo of 64 pixels; the space above it (pt-6) equals the space below it down
           to "Übersicht" (pb-2 here plus pt-4 of the menu) -->
      <div
        class="flex min-h-[4rem] items-center justify-between gap-2 px-4 pb-2 pt-6"
      >
        <InertiaLink
          v-if="!collapsed"
          href="/dashboard"
          class="min-w-0 text-lg font-bold tracking-tight"
        >
          <LayoutBrand size="nav" />
        </InertiaLink>
        <button
          type="button"
          class="rounded-md p-2 text-ink-muted hover:bg-surface-muted hover:text-ink"
          :aria-label="collapsed ? 'Menü ausklappen' : 'Menü einklappen'"
          @click="toggleCollapsed"
        >
          <FontAwesomeIcon
            :icon="['fas', collapsed ? 'chevron-right' : 'chevron-left']"
          />
        </button>
      </div>

      <nav
        class="flex-1 space-y-6 overflow-y-auto px-2 py-4"
        aria-label="Hauptmenü"
      >
        <div v-for="section in navigation" :key="section.key">
          <p
            v-if="!collapsed"
            class="px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-ink-muted"
          >
            {{ section.title }}
          </p>
          <ul class="space-y-1">
            <li v-for="entry in section.entries" :key="entry.title">
              <LayoutMenuEntry :entry="entry" :collapsed="collapsed" />
            </li>
          </ul>
        </div>
      </nav>

      <div class="space-y-1 border-t border-line p-2">
        <InertiaLink
          href="/profile"
          :class="[
            'flex items-center gap-3 rounded-md px-2 py-2 text-sm hover:bg-surface-muted',
            page.url.startsWith('/profile') ? 'bg-accent/10 text-accent' : '',
          ]"
        >
          <UiAvatar :firstname="user.firstname" :lastname="user.lastname" />
          <span v-if="!collapsed" class="truncate font-medium">{{
            user.name
          }}</span>
        </InertiaLink>
        <div
          :class="[
            'flex gap-1',
            collapsed ? 'flex-col items-center' : 'items-center',
          ]"
        >
          <UiColorModeButton />
          <InertiaLink
            href="/logout"
            method="post"
            as="button"
            class="flex items-center gap-2 rounded-md p-2 text-sm text-ink-muted hover:bg-surface-muted hover:text-ink"
          >
            <FontAwesomeIcon :icon="['fas', 'arrow-right-from-bracket']" />
            <span v-if="!collapsed">Abmelden</span>
          </InertiaLink>
        </div>
        <div v-if="!collapsed" class="flex gap-3 px-2 pt-1 text-xs">
          <UiLink
            v-if="organization.imprint_url"
            :href="organization.imprint_url"
            variant="muted"
          >
            Impressum
          </UiLink>
          <UiLink
            v-if="organization.privacy_url"
            :href="organization.privacy_url"
            variant="muted"
          >
            Datenschutz
          </UiLink>
        </div>
      </div>
    </aside>

    <!-- top bar (phone, tablet) -->
    <header
      class="sticky top-0 z-10 flex h-14 items-center justify-between border-b border-line bg-surface px-4 lg:hidden"
    >
      <InertiaLink href="/dashboard" class="min-w-0 font-bold tracking-tight">
        <LayoutBrand />
      </InertiaLink>
      <UiColorModeButton />
    </header>

    <main :class="['pb-24 lg:pb-0', collapsed ? 'lg:pl-16' : 'lg:pl-64']">
      <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <UiFlash />
        <slot />
      </div>
    </main>

    <!-- bottom bar (phone, tablet) -->
    <nav
      class="fixed inset-x-0 bottom-0 z-10 grid border-t border-line bg-surface pb-[env(safe-area-inset-bottom)] lg:hidden"
      :style="{
        gridTemplateColumns: `repeat(${mobileEntries.length + 1}, minmax(0, 1fr))`,
      }"
      aria-label="Schnellzugriff"
    >
      <InertiaLink
        v-for="entry in mobileEntries"
        :key="entry.href"
        :href="entry.href"
        :aria-current="isActive(entry) ? 'page' : undefined"
        :class="[
          'flex min-h-[56px] flex-col items-center justify-center gap-1 text-xs',
          isActive(entry) ? 'text-accent' : 'text-ink-muted',
        ]"
      >
        <FontAwesomeIcon :icon="['fas', entry.icon]" class="h-5 w-5" />
        {{ entry.title }}
      </InertiaLink>
      <button
        type="button"
        class="flex min-h-[56px] flex-col items-center justify-center gap-1 text-xs text-ink-muted"
        @click="moreOpen = true"
      >
        <FontAwesomeIcon :icon="['fas', 'bars']" class="h-5 w-5" />
        Mehr
      </button>
    </nav>

    <!-- full menu (phone, tablet) -->
    <UiModal v-if="moreOpen" title="Menü" @close="moreOpen = false">
      <nav class="space-y-6" aria-label="Hauptmenü">
        <div v-for="section in navigation" :key="section.key">
          <p
            class="pb-1 text-xs font-semibold uppercase tracking-wide text-ink-muted"
          >
            {{ section.title }}
          </p>
          <ul class="space-y-1">
            <li v-for="entry in section.entries" :key="entry.title">
              <LayoutMenuEntry
                :entry="entry"
                large
                @navigate="moreOpen = false"
              />
            </li>
          </ul>
        </div>
        <div class="flex flex-col gap-2 border-t border-line pt-4">
          <UiButton
            href="/profile"
            variant="secondary"
            icon="user"
            @click="moreOpen = false"
          >
            Profil
          </UiButton>
          <UiButton
            href="/logout"
            method="post"
            variant="ghost"
            icon="arrow-right-from-bracket"
          >
            Abmelden
          </UiButton>
        </div>
      </nav>
    </UiModal>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from "vue";
import { Link as InertiaLink, usePage } from "@inertiajs/vue3";

/**
 * Layout of the logged in area. The menu comes from the server (App\Support\Extensions\Navigation):
 * a sidebar on the pc, a bar with the most used entries at the bottom of phones and tablets.
 */
const page = usePage<SharedProps>();

const navigation = computed(() => page.props.navigation);
const organization = computed(() => page.props.organization);
const user = computed(() => page.props.auth!.user);

// at most four entries fit next to "Mehr"
const mobileEntries = computed(() =>
  navigation.value
    .flatMap((section) => section.entries)
    .flatMap((entry) => entry.children ?? [entry])
    .filter((entry) => entry.mobile)
    .slice(0, 4),
);

const isActive = (entry: NavigationEntry) => {
  const path = page.url.split("?")[0];
  return path === entry.href || path.startsWith(`${entry.href}/`);
};

const moreOpen = ref(false);

// the collapsed sidebar is remembered in the browser
const readCollapsed = () => {
  try {
    return localStorage.getItem("sidebarCollapsed") === "1";
  } catch {
    return false;
  }
};
const collapsed = ref(readCollapsed());
const toggleCollapsed = () => {
  collapsed.value = !collapsed.value;
  try {
    localStorage.setItem("sidebarCollapsed", collapsed.value ? "1" : "0");
  } catch {
    // private mode: not remembered
  }
};
</script>
