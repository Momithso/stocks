<template>
  <UiPageHeader
    title="Veranstaltungen"
    description="Eine Veranstaltung fasst mehrere Buchungen mit einer gemeinsamen Materialliste zusammen."
  >
    <UiButton
      variant="secondary"
      :href="past ? '/loans/events' : '/loans/events?past=1'"
      >{{ past ? "Kommende" : "Vergangene" }}</UiButton
    >
    <UiButton v-if="can('loans.manage')" icon="plus" @click="creating = true"
      >Anlegen</UiButton
    >
  </UiPageHeader>

  <UiCard flush>
    <ul v-if="events.length" class="divide-y divide-line">
      <li
        v-for="event in events"
        :key="event.id"
        class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 sm:px-6"
      >
        <span>
          <UiLink :href="`/loans/events/${event.id}`" class="font-medium">{{
            event.name
          }}</UiLink>
          <span class="block text-sm text-ink-muted">
            {{ formatDateTime(event.starts_at) }} bis
            {{ formatDateTime(event.ends_at) }}
            <template v-if="event.place"> · {{ event.place }}</template>
          </span>
        </span>
        <UiBadge>{{ event.loans_count }} Buchungen</UiBadge>
      </li>
    </ul>
    <UiEmptyState
      v-else
      icon="champagne-glasses"
      :title="
        past
          ? 'Keine vergangenen Veranstaltungen'
          : 'Keine kommenden Veranstaltungen'
      "
    />
  </UiCard>

  <LoansEventForm
    v-if="creating"
    :councils="councils"
    @close="creating = false"
  />
</template>

<script setup lang="ts">
import { ref } from "vue";
import { formatDateTime } from "../../http";
import LoansEventForm, { type EventData } from "../../components/EventForm.vue";

/**
 * List of events, coming ones first.
 */
defineProps<{ events: EventData[]; past: boolean; councils: Option[] }>();

const { can } = usePermissions();
const creating = ref(false);
</script>
