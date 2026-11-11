<template>
  <!-- wide: the days scroll sideways, the names of the items stay -->
  <div ref="scroller" class="overflow-x-auto">
    <div :style="{ minWidth: `${(labels ? 12 : 0) + days * 2.4}rem` }">
      <div class="flex border-b border-line text-xs text-ink-muted">
        <div
          v-if="labels"
          class="sticky left-0 z-10 w-48 shrink-0 bg-surface px-2 py-1"
        >
          Gegenstand
        </div>
        <div class="relative flex flex-1">
          <div
            v-for="day in dayList"
            :key="day.getTime()"
            :class="[
              'flex-1 border-l border-line px-0.5 py-1 text-center',
              day.getDay() === 0 || day.getDay() === 6
                ? 'bg-surface-muted'
                : '',
              day.getTime() === today ? 'font-bold text-accent' : '',
            ]"
          >
            {{ day.getDate() }}.{{ day.getMonth() + 1 }}.
          </div>
        </div>
      </div>
      <div v-for="row in rows" :key="row.id" class="flex border-b border-line">
        <div
          v-if="labels"
          class="sticky left-0 z-10 w-48 shrink-0 truncate bg-surface px-2 py-1.5 text-sm"
          :title="`${row.number} ${row.name}`"
        >
          <a
            v-if="linkItems"
            :href="`/inventory/items/${row.id}`"
            class="hover:text-accent"
            >{{ row.name }}</a
          >
          <template v-else>{{ row.name }}</template>
          <span class="block font-mono text-xs text-ink-muted">{{
            row.number
          }}</span>
        </div>
        <div class="relative min-h-[3.25rem] flex-1">
          <div class="absolute inset-0 flex">
            <div
              v-for="day in dayList"
              :key="day.getTime()"
              :class="[
                'flex-1 border-l border-line',
                day.getDay() === 0 || day.getDay() === 6
                  ? 'bg-surface-muted'
                  : '',
              ]"
            />
          </div>
          <button
            v-for="(bar, index) in barsOf(row.loans)"
            :key="bar.loan.id"
            type="button"
            :class="[
              'absolute h-5 truncate rounded px-1.5 text-left text-xs',
              barStyle(bar.loan).class,
            ]"
            :style="{
              ...barStyle(bar.loan).style,
              left: `${bar.left}%`,
              width: `${bar.width}%`,
              top: `${0.4 + (index % 2) * 0.3}rem`,
            }"
            :title="`${bar.loan.title} · ${bar.loan.status_label}`"
            @click="emit('open', bar.loan)"
          >
            {{ bar.loan.number }}
          </button>
        </div>
      </div>
      <p v-if="!rows.length" class="px-2 py-4 text-sm text-ink-muted">
        In diesem Zeitraum ist nichts gebucht.
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from "vue";
import type { LoanSummary } from "../http";
import { DAY, addDays, barStyle, endOf, startOfDay } from "../calendar";

export type TimelineRow = {
  id: number;
  number: string;
  name: string;
  loans: LoanSummary[];
};

/**
 * Timeline: one row per item, one bar per loan, over a number of days. Without "labels" the column
 * with the names is left out, e.g. on the page of the item itself.
 */
const props = withDefaults(
  defineProps<{
    rows: TimelineRow[];
    from: Date;
    days: number;
    linkItems?: boolean;
    labels?: boolean;
  }>(),
  { linkItems: true, labels: true },
);
const emit = defineEmits<{ open: [loan: LoanSummary] }>();

const today = startOfDay(new Date()).getTime();
const dayList = computed(() =>
  Array.from({ length: props.days }, (_, index) => addDays(props.from, index)),
);

// a narrow screen starts at yesterday instead of the first day shown
const scroller = ref<HTMLDivElement | null>(null);
const showToday = async () => {
  await nextTick();
  const element = scroller.value;
  const index = dayList.value.findIndex((day) => day.getTime() === today);
  if (!element || index < 1) {
    return;
  }
  const labelWidth = props.labels ? 12 * 16 : 0;
  const dayWidth = (element.scrollWidth - labelWidth) / props.days;
  element.scrollLeft = (index - 1) * dayWidth;
};
onMounted(showToday);
watch(() => props.from, showToday);

// position of the bars in percent of the time shown
const barsOf = (loans: LoanSummary[]) => {
  const start = startOfDay(props.from).getTime();
  const span = props.days * DAY;
  return (
    loans
      .map((loan) => ({
        loan,
        from: new Date(loan.pickup_at).getTime() - start,
        to: endOf(loan).getTime() - start,
      }))
      // only loans that touch the time shown
      .filter((bar) => bar.to > 0 && bar.from < span)
      .map(({ loan, from, to }) => {
        const left = Math.max(0, from);
        const right = Math.min(span, to);
        return {
          loan,
          left: (left / span) * 100,
          width: Math.max(1, ((right - left) / span) * 100),
        };
      })
  );
};
</script>
