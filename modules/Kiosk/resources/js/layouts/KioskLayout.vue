<template>
  <div class="flex min-h-screen flex-col text-lg">
    <header
      class="sticky top-0 z-10 flex min-h-[4rem] flex-wrap items-center justify-between gap-3 border-b border-line bg-surface px-4 py-2 sm:px-6"
    >
      <p class="min-w-0 text-xl font-bold tracking-tight">
        <LayoutBrand :text="`${organization.short_name} · Kiosk`" />
      </p>

      <!-- unlocked: who works here, remaining time, extend, home, lock -->
      <div v-if="person" class="flex flex-wrap items-center gap-2">
        <span class="hidden text-base text-ink-muted sm:inline">
          {{ person.firstname }} {{ person.lastname }}
        </span>
        <span
          :class="[
            'min-w-[4.5rem] rounded-md px-3 py-1.5 text-center font-mono text-base font-semibold tabular-nums',
            remaining <= 20
              ? 'bg-red-600 text-white'
              : 'bg-surface-muted text-ink',
          ]"
          :title="failed ? 'Keine Verbindung' : 'Zeit bis zur Sperre'"
          aria-live="polite"
        >
          {{ clock }}
        </span>
        <UiButton variant="secondary" icon="rotate" @click="extend">
          Verlängern
        </UiButton>
        <!-- installed as web app the kiosk has no back button of the browser -->
        <UiButton
          v-if="!onHome"
          variant="secondary"
          icon="chevron-left"
          @click="goBack"
        >
          <span class="sr-only sm:not-sr-only">Zurück</span>
        </UiButton>
        <UiButton
          v-if="!onHome"
          variant="secondary"
          icon="house"
          href="/kiosk/home"
        >
          <span class="sr-only sm:not-sr-only">Startseite</span>
        </UiButton>
        <UiButton
          variant="ghost"
          icon="lock"
          href="/kiosk/logout"
          method="post"
        >
          <span class="sr-only sm:not-sr-only">Sperren</span>
        </UiButton>
      </div>
      <UiColorModeButton v-else />
    </header>
    <p
      v-if="person && failed"
      class="bg-red-50 px-6 py-1 text-sm text-red-700 dark:bg-red-950 dark:text-red-200"
    >
      Keine Verbindung. Die Zeit läuft weiter, bei null sperrt der Kiosk.
    </p>
    <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-6 sm:px-6">
      <UiFlash />
      <slot />
    </main>
  </div>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import useKioskTimer from "../composables/useKioskTimer";

/**
 * Layout of the kiosk on tablets: no menu, large text. Unlocked, the bar on top shows the person,
 * the remaining time (red in the last 20 seconds) and the buttons to extend and to lock.
 */
const page = usePage<SharedProps>();
const organization = computed(() => page.props.organization);
const person = computed(() => page.props.surface?.person ?? null);
const onHome = computed(() => page.url.split("?")[0] === "/kiosk/home");
const goBack = () => window.history.back();

const { remaining, failed, extend } = useKioskTimer(
  computed(() => page.props.surface?.expires_in),
);
const clock = computed(
  () =>
    `${Math.floor(remaining.value / 60)}:${String(remaining.value % 60).padStart(2, "0")}`,
);
</script>
