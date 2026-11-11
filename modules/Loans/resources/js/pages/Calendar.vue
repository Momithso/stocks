<template>
  <UiPageHeader title="Kalender">
    <UiButton v-if="can('loans.manage')" icon="plus" href="/loans/create"
      >Neue Buchung</UiButton
    >
  </UiPageHeader>

  <UiCard flush>
    <div
      class="flex flex-wrap items-center gap-3 border-b border-line p-4 sm:px-6"
    >
      <div class="flex rounded-md ring-1 ring-line">
        <button
          v-for="option in views"
          :key="option.value"
          type="button"
          :class="[
            'px-3 py-1.5 text-sm',
            view === option.value
              ? 'bg-accent text-white'
              : 'hover:bg-surface-muted',
          ]"
          @click="view = option.value"
        >
          {{ option.label }}
        </button>
      </div>
      <div class="flex items-center gap-1">
        <UiButton
          variant="ghost"
          size="sm"
          icon="chevron-left"
          aria-label="Zurück"
          @click="move(-1)"
        />
        <span class="min-w-40 text-center font-semibold">{{ title }}</span>
        <UiButton
          variant="ghost"
          size="sm"
          icon="chevron-right"
          aria-label="Weiter"
          @click="move(1)"
        />
        <UiButton variant="secondary" size="sm" @click="anchor = new Date()"
          >Heute</UiButton
        >
      </div>
      <template v-if="view === 'timeline'">
        <UiSelect
          v-model="category"
          aria-label="Kategorie"
          :options="categories"
          class="w-48"
        />
        <input
          v-model="search"
          type="search"
          placeholder="Gegenstand suchen"
          aria-label="Gegenstand suchen"
          class="rounded-md border-0 bg-surface py-1.5 text-sm text-ink ring-1 ring-inset ring-line"
        />
      </template>
    </div>

    <div class="p-4 sm:px-6">
      <LoansMonthCalendar
        v-if="view === 'month'"
        :month="anchor"
        :loans="loans"
        :can-create="can('loans.manage')"
        @open="open"
        @create="(date) => router.visit(`/loans/create?date=${date}`)"
      />
      <LoansTimeline
        v-else
        :rows="rows"
        :from="timelineFrom"
        :days="timelineDays"
        @open="open"
      />
    </div>
    <p
      class="flex flex-wrap gap-3 border-t border-line px-4 py-3 text-xs text-ink-muted sm:px-6"
    >
      <span
        ><span
          class="mr-1 inline-block h-3 w-3 rounded bg-accent align-middle"
        />bestätigt</span
      >
      <span
        ><span
          class="mr-1 inline-block h-3 w-3 rounded bg-yellow-500 align-middle"
        />ausgegeben</span
      >
      <span
        ><span
          class="mr-1 inline-block h-3 w-3 rounded bg-red-600 align-middle"
        />überfällig</span
      >
      <span
        ><span
          class="mr-1 inline-block h-3 w-3 rounded bg-orange-400 align-middle"
        />ungeprüft</span
      >
      <span
        ><span
          class="mr-1 inline-block h-3 w-3 rounded align-middle ring-1 ring-line"
          style="
            background: repeating-linear-gradient(
              135deg,
              #ddd 0 3px,
              #fff 3px 6px
            );
          "
        />Entwurf (Vormerkung)</span
      >
    </p>
  </UiCard>
</template>

<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import { getJson, type LoanSummary } from "../http";
import { addDays, isoDate, startOfWeek } from "../calendar";
import LoansMonthCalendar from "../components/MonthCalendar.vue";
import LoansTimeline, { type TimelineRow } from "../components/Timeline.vue";

/**
 * Calendar of the loans: month view or timeline per item. The data are loaded as json for the time
 * shown.
 */
defineProps<{ categories: Option[] }>();

const { can } = usePermissions();
const views = [
  { value: "month", label: "Monat" },
  { value: "timeline", label: "Zeitstrahl" },
] as const;
const view = ref<"month" | "timeline">("month");
const anchor = ref(new Date());
const category = ref<number | null>(null);
const search = ref("");

const timelineDays = 28;
const timelineFrom = computed(() => startOfWeek(anchor.value));
const title = computed(() =>
  view.value === "month"
    ? anchor.value.toLocaleDateString("de-DE", {
        month: "long",
        year: "numeric",
      })
    : `${timelineFrom.value.toLocaleDateString("de-DE")} – ${addDays(timelineFrom.value, timelineDays - 1).toLocaleDateString("de-DE")}`,
);

const move = (step: number) => {
  anchor.value =
    view.value === "month"
      ? new Date(anchor.value.getFullYear(), anchor.value.getMonth() + step, 1)
      : addDays(anchor.value, step * 7);
};

const loans = ref<LoanSummary[]>([]);
const rows = ref<TimelineRow[]>([]);

let timeout: ReturnType<typeof setTimeout> | null = null;
const load = () => {
  if (timeout) {
    clearTimeout(timeout);
  }
  timeout = setTimeout(async () => {
    if (view.value === "month") {
      const first = startOfWeek(
        new Date(anchor.value.getFullYear(), anchor.value.getMonth(), 1),
      );
      loans.value =
        (await getJson<LoanSummary[]>(
          `/loans/calendar/loans?from=${isoDate(first)}&to=${isoDate(addDays(first, 42))}`,
        )) ?? [];
    } else {
      const query = new URLSearchParams({
        from: isoDate(timelineFrom.value),
        to: isoDate(addDays(timelineFrom.value, timelineDays)),
        search: search.value,
      });
      if (category.value) {
        query.set("category", String(category.value));
      }
      rows.value =
        (await getJson<TimelineRow[]>(`/loans/calendar/timeline?${query}`)) ??
        [];
    }
  }, 200);
};
watch([view, anchor, category, search], load, { immediate: true });

const open = (loan: LoanSummary) => router.visit(`/loans/${loan.id}`);
</script>
