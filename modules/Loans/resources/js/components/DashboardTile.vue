<template>
  <UiCard title="Verleih" class="md:col-span-2 xl:col-span-2">
    <div v-if="stats" class="space-y-4">
      <dl class="grid grid-cols-3 gap-4">
        <div>
          <dt class="text-sm text-ink-muted">Heute abholen</dt>
          <dd class="text-2xl font-semibold">
            <UiLink
              :href="`/loans?status=confirmed&from=${today}&to=${today}`"
              >{{ stats.pickup_today }}</UiLink
            >
          </dd>
        </div>
        <div>
          <dt class="text-sm text-ink-muted">Heute zurück</dt>
          <dd class="text-2xl font-semibold">
            <UiLink :href="`/loans?status=issued&from=${today}&to=${today}`">{{
              stats.return_today
            }}</UiLink>
          </dd>
        </div>
        <div>
          <dt class="text-sm text-ink-muted">Überfällig</dt>
          <dd
            :class="[
              'text-2xl font-semibold',
              stats.overdue_count ? 'text-red-600' : '',
            ]"
          >
            <UiLink href="/loans?status=overdue">{{
              stats.overdue_count
            }}</UiLink>
          </dd>
        </div>
        <template v-if="stats.unchecked !== undefined">
          <div>
            <dt class="text-sm text-ink-muted">Prüfung offen</dt>
            <dd class="text-2xl font-semibold">
              <UiLink href="/loans/returns">{{ stats.unchecked }}</UiLink>
            </dd>
          </div>
          <div>
            <dt class="text-sm text-ink-muted">Mängel offen</dt>
            <dd class="text-2xl font-semibold">
              <UiLink href="/loans/returns">{{ stats.defects }}</UiLink>
            </dd>
          </div>
          <div>
            <dt class="text-sm text-ink-muted">Nicht eingeräumt</dt>
            <dd class="text-2xl font-semibold">
              <UiLink href="/loans/returns">{{ stats.not_stowed }}</UiLink>
            </dd>
          </div>
        </template>
      </dl>
      <ul
        v-if="stats.overdue.length"
        class="divide-y divide-line border-t border-line"
      >
        <li
          v-for="loan in stats.overdue"
          :key="loan.id"
          class="flex items-center justify-between gap-2 py-2 text-sm"
        >
          <UiLink :href="`/loans/${loan.id}`">{{ loan.title }}</UiLink>
          <UiBadge variant="danger"
            >{{ loan.overdue_days }}
            {{ loan.overdue_days === 1 ? "Tag" : "Tage" }}</UiBadge
          >
        </li>
      </ul>
    </div>
    <div v-else class="h-16 animate-pulse rounded bg-surface-muted" />
  </UiCard>
</template>

<script setup lang="ts">
import { onMounted, ref } from "vue";
import { getJson, type LoanSummary } from "../http";
import { isoDate } from "../calendar";

/**
 * Dashboard tile of the loans: pick up today, back today, overdue with days; for members who
 * issue also open inspections, open defects and items not put back yet.
 */
type Stats = {
  pickup_today: number;
  return_today: number;
  overdue_count: number;
  overdue: LoanSummary[];
  unchecked?: number;
  defects?: number;
  not_stowed?: number;
};
const stats = ref<Stats | null>(null);
const today = isoDate(new Date());

onMounted(async () => {
  stats.value = await getJson<Stats>("/loans/stats");
});
</script>
