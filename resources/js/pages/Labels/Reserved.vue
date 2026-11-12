<template>
  <UiPageHeader
    title="Etiketten auf Vorrat"
    description="Nummern vorab reservieren und drucken. Wer später so ein Etikett scannt, legt den Gegenstand mit genau dieser Nummer an."
  >
    <UiButton
      v-if="demoSheet"
      variant="secondary"
      icon="file-pdf"
      href="/labels/demo-sheet.pdf"
      external
    >
      Testetiketten (Demo)
    </UiButton>
  </UiPageHeader>

  <div class="grid gap-6 lg:grid-cols-3">
    <UiCard title="Auf Vorrat drucken">
      <form class="space-y-5" @submit.prevent="reserve">
        <UiInput
          v-model="form.count"
          label="Anzahl"
          type="number"
          inputmode="numeric"
          required
          :hint="`Höchstens ${max} auf einmal. Danach öffnet sich die Druckvorschau mit der Vorlage „Vorrat“.`"
          :error="form.errors.count"
        />
        <UiButton
          type="submit"
          class="w-full"
          icon="print"
          :disabled="form.processing"
        >
          Nummern reservieren und drucken
        </UiButton>
      </form>
    </UiCard>

    <UiCard title="Offene Reservierungen" class="lg:col-span-2" flush>
      <template v-if="reserved.length" #actions>
        <UiButton
          variant="secondary"
          size="sm"
          icon="print"
          :disabled="!selected.length"
          :href="`/labels/print?reserved=${selected.join(',')}`"
        >
          Erneut drucken
        </UiButton>
        <UiButton
          variant="ghost"
          size="sm"
          :disabled="!selected.length"
          @click="releaseOpen = true"
        >
          Freigeben
        </UiButton>
      </template>
      <ul v-if="reserved.length" class="divide-y divide-line">
        <li
          v-for="entry in reserved"
          :key="entry.id"
          class="flex items-center gap-3 px-4 py-2 sm:px-6"
        >
          <input
            v-model="selected"
            type="checkbox"
            :value="entry.id"
            class="h-4 w-4 rounded border-line text-accent focus:ring-accent"
            :aria-label="entry.number"
          />
          <span class="font-mono">{{ entry.number }}</span>
          <span class="ml-auto text-sm text-ink-muted">
            <UiDateTime :value="entry.created_at" :with-time="false" />
            <template v-if="entry.created_by">
              · {{ entry.created_by }}</template
            >
          </span>
        </li>
      </ul>
      <UiEmptyState
        v-else
        icon="layer-group"
        title="Keine offenen Reservierungen"
      />
    </UiCard>
  </div>

  <UiConfirmModal
    v-if="releaseOpen"
    title="Reservierungen freigeben"
    :message="`${selected.length} Nummern werden freigegeben. Sie werden nie neu vergeben; gedruckte Etiketten dazu sind dann ungültig.`"
    @close="releaseOpen = false"
    @confirm="release"
  />
</template>

<script setup lang="ts">
import { ref } from "vue";
import { router, useForm } from "@inertiajs/vue3";

/**
 * Reserve numbers for stock labels, print them again, or release unused ones.
 */
defineProps<{
  reserved: {
    id: number;
    number: string;
    created_at: string;
    created_by: string | null;
  }[];
  max: number;
  // instance with demo data: the test label sheet can be downloaded
  demoSheet: boolean;
}>();

const form = useForm({ count: 10 as number | string });
const reserve = () => form.post("/labels/reserved");

const selected = ref<number[]>([]);
const releaseOpen = ref(false);
const release = () =>
  router.post(
    "/labels/reserved/release",
    { ids: selected.value },
    {
      preserveScroll: true,
      onSuccess: () => (selected.value = []),
      onFinish: () => (releaseOpen.value = false),
    },
  );
</script>
