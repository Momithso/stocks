<template>
  <span class="inline-flex flex-wrap gap-1">
    <UiBadge :variant="loan.status_variant">{{ loan.status_label }}</UiBadge>
    <UiBadge v-if="loan.overdue_days" variant="danger">
      {{ loan.overdue_days }} {{ loan.overdue_days === 1 ? "Tag" : "Tage" }}
      überfällig
    </UiBadge>
  </span>
</template>

<script setup lang="ts">
import type { LoanSummary } from "../http";

/**
 * Status of a loan; an overdue loan also shows the days.
 */
defineProps<{
  loan: Pick<LoanSummary, "status_label" | "status_variant" | "overdue_days">;
}>();
</script>
