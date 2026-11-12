<template>
  <UiCard
    title="Verleihseite"
    description="Seite für den Entleiher, ohne Konto."
  >
    <UiAlert v-if="page.blocked" type="warning" class="mb-3"
      >Gesperrt: der Link öffnet nichts.</UiAlert
    >
    <UiAlert v-else-if="!page.open" type="info" class="mb-3"
      >Abgelaufen.</UiAlert
    >
    <div class="flex flex-wrap gap-2">
      <UiButton variant="secondary" size="sm" icon="copy" @click="copy">{{
        copied ? "Kopiert" : "Link kopieren"
      }}</UiButton>
      <UiButton
        variant="secondary"
        size="sm"
        icon="qrcode"
        @click="qrOpen = true"
        >QR-Code</UiButton
      >
      <UiButton
        v-if="page.open"
        variant="secondary"
        size="sm"
        icon="arrow-up-right-from-square"
        :href="page.url"
        external
        >Öffnen</UiButton
      >
    </div>
    <dl class="mt-4 space-y-2 text-sm">
      <div>
        <dt class="text-ink-muted">Gültig</dt>
        <dd>
          <template v-if="page.expires_at"
            >bis <UiDateTime :value="page.expires_at"
          /></template>
          <template v-else
            >solange die Buchung läuft, danach noch
            {{ page.days_after_close }} Tage</template
          >
        </dd>
      </div>
      <div>
        <dt class="text-ink-muted">Geräte mit Push</dt>
        <dd>{{ page.push_devices }}</dd>
      </div>
    </dl>
    <div class="mt-4 flex flex-wrap gap-2 border-t border-line pt-3">
      <UiButton
        variant="ghost"
        size="sm"
        :icon="page.blocked ? 'lock-open' : 'lock'"
        @click="block(!page.blocked)"
        >{{ page.blocked ? "Entsperren" : "Sperren" }}</UiButton
      >
      <UiButton
        variant="ghost"
        size="sm"
        icon="rotate"
        @click="reissueOpen = true"
        >Neu ausstellen</UiButton
      >
    </div>
  </UiCard>

  <UiModal
    v-if="qrOpen"
    title="QR-Code der Verleihseite"
    @close="qrOpen = false"
  >
    <!-- svg made by the server from the link -->
    <div class="mx-auto w-56 rounded bg-white p-2" v-html="page.qr" />
    <p class="mt-3 break-all text-center font-mono text-xs text-ink-muted">
      {{ page.url }}
    </p>
  </UiModal>

  <UiConfirmModal
    v-if="reissueOpen"
    title="Neuen Link ausstellen"
    message="Der alte Link und sein QR-Code gehen danach nicht mehr, auch nicht auf den schon gedruckten Dokumenten. Eine App auf dem Home-Bildschirm des Entleihers funktioniert nicht mehr, Push an seine Geräte endet. Der neue Link steht in den nächsten Nachrichten."
    @close="reissueOpen = false"
    @confirm="reissue"
  />
</template>

<script setup lang="ts">
import { ref } from "vue";
import { router } from "@inertiajs/vue3";

export type BorrowerPage = {
  url: string;
  qr: string;
  open: boolean;
  blocked: boolean;
  expires_at: string | null;
  days_after_close: number;
  push_devices: number;
};

/**
 * Card "Verleihseite" of a loan: copy the link, show the code, block, issue a new link.
 */
const props = defineProps<{ loanId: number; page: BorrowerPage }>();

const qrOpen = ref(false);
const reissueOpen = ref(false);
const copied = ref(false);

const copy = async () => {
  await navigator.clipboard.writeText(props.page.url);
  copied.value = true;
  setTimeout(() => (copied.value = false), 2000);
};

const options = { preserveScroll: true };
const block = (blocked: boolean) =>
  router.post(`/loans/${props.loanId}/page/block`, { blocked }, options);
const reissue = () =>
  router.post(
    `/loans/${props.loanId}/page/reissue`,
    {},
    {
      ...options,
      onFinish: () => (reissueOpen.value = false),
    },
  );
</script>
