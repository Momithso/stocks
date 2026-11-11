<template>
  <UiPageHeader :title="contact.name" :description="contact.affiliation">
    <UiButton variant="secondary" icon="pen" @click="editing = true"
      >Bearbeiten</UiButton
    >
    <UiButton
      v-if="!loans.length"
      variant="ghost"
      icon="trash"
      @click="deleteOpen = true"
      >Löschen</UiButton
    >
  </UiPageHeader>

  <UiAlert v-if="page.props.errors?.contact" type="error" class="mb-4">{{
    page.props.errors.contact
  }}</UiAlert>
  <UiAlert v-if="contact.missing?.length" type="warning" class="mb-4">
    Für eine Buchung als verantwortliche Person fehlen noch:
    {{ contact.missing.join(", ") }}.
  </UiAlert>

  <div class="grid gap-6 lg:grid-cols-3">
    <UiCard title="Kontaktdaten">
      <dl class="space-y-3 text-sm">
        <div>
          <dt class="text-ink-muted">E-Mail</dt>
          <dd>{{ contact.email ?? "–" }}</dd>
        </div>
        <div>
          <dt class="text-ink-muted">Telefon</dt>
          <dd>{{ contact.phone ?? "–" }}</dd>
        </div>
        <div>
          <dt class="text-ink-muted">Anschrift</dt>
          <dd>{{ contact.address || "–" }}</dd>
        </div>
        <div v-if="contact.notes">
          <dt class="text-ink-muted">Notiz</dt>
          <dd class="whitespace-pre-line">{{ contact.notes }}</dd>
        </div>
      </dl>
    </UiCard>
    <UiCard title="Buchungen" class="lg:col-span-2" flush>
      <ul v-if="loans.length" class="divide-y divide-line">
        <li
          v-for="loan in loans"
          :key="loan.id"
          class="flex flex-wrap items-center justify-between gap-2 px-4 py-2 sm:px-6"
        >
          <span>
            <UiLink :href="`/loans/${loan.id}`" class="font-medium">{{
              loan.title
            }}</UiLink>
            <span class="block text-xs text-ink-muted"
              >{{ formatDateTime(loan.pickup_at) }} bis
              {{ formatDateTime(loan.return_at) }}</span
            >
          </span>
          <LoansStatusBadge :loan="loan" />
        </li>
      </ul>
      <UiEmptyState v-else icon="calendar-check" title="Noch keine Buchungen" />
    </UiCard>
  </div>

  <LoansContactForm
    v-if="editing"
    :contact="contact"
    :councils="councils"
    @close="editing = false"
    @saved="saved"
  />
  <UiConfirmModal
    v-if="deleteOpen"
    title="Kontakt löschen"
    :message="`${contact.name} wird gelöscht.`"
    @close="deleteOpen = false"
    @confirm="router.delete(`/loans/contacts/${contact.id}`)"
  />
</template>

<script setup lang="ts">
import { ref } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { formatDateTime, type Contact, type LoanSummary } from "../../http";
import LoansContactForm from "../../components/ContactForm.vue";
import LoansStatusBadge from "../../components/StatusBadge.vue";

/**
 * A contact with its loans. A contact with loans can not be deleted.
 */
defineProps<{ contact: Contact; loans: LoanSummary[]; councils: Option[] }>();

const page = usePage<SharedProps>();
const editing = ref(false);
const deleteOpen = ref(false);
const saved = () => {
  editing.value = false;
  router.reload();
};
</script>
