<template>
  <!-- phones: list by days with pickups and returns -->
  <ol class="space-y-4 sm:hidden">
    <li v-for="day in listDays" :key="day.key">
      <p class="text-sm font-semibold">{{ day.label }}</p>
      <ul class="mt-1 space-y-1">
        <li v-for="entry in day.entries" :key="entry.key">
          <button
            type="button"
            :class="[
              'w-full rounded px-3 py-2 text-left text-sm',
              barStyle(entry.loan).class,
            ]"
            :style="barStyle(entry.loan).style"
            @click="emit('open', entry.loan)"
          >
            <strong>{{ entry.what }}</strong> · {{ entry.loan.title }}
          </button>
        </li>
      </ul>
    </li>
    <li v-if="!listDays.length" class="text-sm text-ink-muted">
      In diesem Monat ist nichts gebucht.
    </li>
  </ol>

  <!-- pc and tablet: month grid with a bar per loan -->
  <div class="hidden sm:block">
    <div
      class="grid grid-cols-7 border-b border-line text-xs font-semibold text-ink-muted"
    >
      <div v-for="name in weekdays" :key="name" class="px-2 py-1">
        {{ name }}
      </div>
    </div>
    <div
      v-for="week in weeks"
      :key="week.start.getTime()"
      class="relative grid grid-cols-7 border-b border-line"
      :style="{ minHeight: `${3 + week.lanes * 1.6}rem` }"
    >
      <button
        v-for="day in week.days"
        :key="day.getTime()"
        type="button"
        :class="[
          'flex flex-col items-start justify-start border-r border-line px-2 py-1 text-left text-xs last:border-r-0 hover:bg-surface-muted',
          day.getMonth() === month.getMonth() ? '' : 'text-ink-muted/60',
        ]"
        :title="canCreate ? 'Neue Buchung an diesem Tag' : undefined"
        @click="canCreate && emit('create', isoDate(day))"
      >
        <span
          :class="
            isToday(day) ? 'rounded-full bg-accent px-1.5 text-white' : ''
          "
          >{{ day.getDate() }}</span
        >
      </button>
      <button
        v-for="bar in week.bars"
        :key="bar.loan.id"
        type="button"
        :class="[
          'absolute h-6 truncate rounded px-2 text-left text-xs font-medium',
          barStyle(bar.loan).class,
        ]"
        :style="{
          ...barStyle(bar.loan).style,
          left: `calc(${(bar.start * 100) / 7}% + 2px)`,
          width: `calc(${((bar.end - bar.start + 1) * 100) / 7}% - 4px)`,
          top: `${1.6 + bar.lane * 1.6}rem`,
        }"
        :title="`${bar.loan.title} · ${bar.loan.status_label}`"
        @click="emit('open', bar.loan)"
      >
        {{ bar.loan.title }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from "vue";
import type { LoanSummary } from "../http";
import {
  DAY,
  addDays,
  barStyle,
  endOf,
  isoDate,
  startOfDay,
  startOfWeek,
} from "../calendar";

/**
 * Month calendar of the loans: every loan is a bar from pickup to return, coloured by status;
 * drafts are hatched, overdue loans red up to today. A click on a bar opens the loan, a click on a
 * free day starts a new loan with this date.
 */
const props = defineProps<{
  // any day of the month
  month: Date;
  loans: LoanSummary[];
  canCreate: boolean;
}>();
const emit = defineEmits<{
  open: [loan: LoanSummary];
  create: [date: string];
}>();

const weekdays = ["Mo", "Di", "Mi", "Do", "Fr", "Sa", "So"];
const today = startOfDay(new Date()).getTime();
const isToday = (day: Date) => day.getTime() === today;

const weeks = computed(() => {
  const first = new Date(props.month.getFullYear(), props.month.getMonth(), 1);
  const last = new Date(
    props.month.getFullYear(),
    props.month.getMonth() + 1,
    0,
  );
  const result = [];

  for (
    let start = startOfWeek(first);
    start <= last;
    start = addDays(start, 7)
  ) {
    const end = addDays(start, 7);
    const lanes: number[] = [];
    const bars = props.loans
      .filter((loan) => new Date(loan.pickup_at) < end && endOf(loan) > start)
      .map((loan) => {
        const from = Math.max(
          0,
          Math.floor(
            (startOfDay(new Date(loan.pickup_at)).getTime() - start.getTime()) /
              DAY,
          ),
        );
        const to = Math.min(
          6,
          Math.floor(
            (startOfDay(endOf(loan)).getTime() - start.getTime()) / DAY,
          ),
        );
        // the first lane that is free from this day on
        let lane = lanes.findIndex((busyUntil) => busyUntil < from);
        if (lane === -1) {
          lane = lanes.length;
        }
        lanes[lane] = to;
        return { loan, start: from, end: Math.max(from, to), lane };
      });

    result.push({
      start,
      days: Array.from({ length: 7 }, (_, index) => addDays(start, index)),
      bars,
      lanes: lanes.length,
    });
  }
  return result;
});

const listDays = computed(() => {
  const days = new Map<
    string,
    {
      key: string;
      label: string;
      entries: { key: string; what: string; loan: LoanSummary }[];
    }
  >();
  const add = (date: Date, what: string, loan: LoanSummary) => {
    if (date.getMonth() !== props.month.getMonth()) {
      return;
    }
    const key = isoDate(date);
    if (!days.has(key)) {
      days.set(key, {
        key,
        label: date.toLocaleDateString("de-DE", {
          weekday: "long",
          day: "numeric",
          month: "long",
        }),
        entries: [],
      });
    }
    days.get(key)!.entries.push({ key: `${what}-${loan.id}`, what, loan });
  };
  for (const loan of props.loans) {
    add(new Date(loan.pickup_at), "Abholung", loan);
    add(
      new Date(loan.return_at),
      loan.overdue_days ? "Rückgabe (überfällig)" : "Rückgabe",
      loan,
    );
  }
  return [...days.values()].sort((a, b) => a.key.localeCompare(b.key));
});
</script>
