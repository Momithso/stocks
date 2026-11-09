<template>
  <UiPageHeader
    title="Styleguide"
    description="Alle Basiskomponenten aus resources/js/components/ui. Mit dem Mond- oder Sonnen-Symbol zwischen hell und dunkel wechseln."
  >
    <UiColorModeButton />
  </UiPageHeader>

  <div class="space-y-6">
    <UiCard title="Farben" description="Tokens aus resources/css/app.css">
      <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div v-for="color in colors" :key="color.name" class="space-y-1">
          <div :class="['h-12 rounded-md ring-1 ring-line', color.class]" />
          <p class="text-sm font-medium">{{ color.name }}</p>
        </div>
      </div>
    </UiCard>

    <UiCard title="Schrift">
      <div class="space-y-2">
        <p class="text-3xl font-bold tracking-tight">Seitentitel</p>
        <p class="text-base font-semibold">Kartentitel</p>
        <p>Fließtext in Inter.</p>
        <p class="text-sm text-ink-muted">Nebentext</p>
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">
          Menübereich
        </p>
      </div>
    </UiCard>

    <UiCard title="Button">
      <div class="flex flex-wrap items-center gap-3">
        <UiButton>Primär</UiButton>
        <UiButton variant="secondary">Sekundär</UiButton>
        <UiButton variant="danger" icon="trash">Löschen</UiButton>
        <UiButton variant="ghost">Dezent</UiButton>
        <UiButton disabled>Gesperrt</UiButton>
        <UiButton size="sm">Klein</UiButton>
        <UiButton size="lg" icon="lock">Kiosk</UiButton>
      </div>
    </UiCard>

    <UiCard title="Link, Badge, Avatar, Datum">
      <div class="flex flex-wrap items-center gap-4">
        <UiLink href="/styleguide">Link</UiLink>
        <UiLink href="/styleguide" variant="muted">Dezenter Link</UiLink>
        <UiBadge>neutral</UiBadge>
        <UiBadge variant="accent">Rolle</UiBadge>
        <UiBadge variant="success">verfügbar</UiBadge>
        <UiBadge variant="warning">ungeprüft</UiBadge>
        <UiBadge variant="danger">überfällig</UiBadge>
        <UiAvatar firstname="Ada" lastname="Lovelace" />
        <UiAvatar firstname="Ada" lastname="Lovelace" size="lg" />
        <UiDateTime value="2026-11-09T14:30:00+01:00" />
      </div>
    </UiCard>

    <UiCard title="Hinweise">
      <div class="space-y-3">
        <UiAlert type="info" message="Information" />
        <UiAlert type="success" message="Gespeichert" />
        <UiAlert type="warning" message="Inhalt nicht geprüft" />
        <UiAlert type="error" message="Fehler" />
      </div>
    </UiCard>

    <UiCard title="Formular">
      <div class="grid gap-5 sm:grid-cols-2">
        <UiInput v-model="text" label="Textfeld" hint="Mit Hinweis" />
        <UiInput
          v-model="text"
          label="Mit Fehler"
          error="Bitte ausfüllen."
          required
        />
        <UiSelect v-model="choice" label="Auswahl" :options="options" />
        <div class="space-y-3">
          <UiCheckbox
            v-model="checked"
            label="Checkbox"
            description="Mit Beschreibung"
          />
          <UiCheckbox label="Gesperrt" disabled />
        </div>
      </div>
    </UiCard>

    <UiCard title="Dialoge, Leerzustand, PIN">
      <div class="flex flex-wrap gap-3">
        <UiButton variant="secondary" @click="modalOpen = true"
          >Dialog öffnen</UiButton
        >
        <UiButton variant="secondary" @click="confirmOpen = true"
          >Rückfrage öffnen</UiButton
        >
      </div>
      <UiEmptyState
        icon="house"
        title="Leerzustand"
        description="Erscheint statt einer leeren Liste."
      />
      <div class="mx-auto max-w-xs"><UiPinPad /></div>
    </UiCard>

    <UiCard title="Karte mit Aktionen" description="Beschreibung">
      <template #actions><UiButton size="sm">Aktion</UiButton></template>
      Inhalt
      <template #footer
        ><UiButton variant="secondary">Fußzeile</UiButton></template
      >
    </UiCard>
  </div>

  <UiModal v-if="modalOpen" title="Dialog" @close="modalOpen = false">
    <p>Inhalt des Dialogs.</p>
  </UiModal>
  <UiConfirmModal
    v-if="confirmOpen"
    title="Wirklich löschen?"
    message="Das lässt sich nicht rückgängig machen."
    @close="confirmOpen = false"
    @confirm="confirmOpen = false"
  />
</template>

<script setup lang="ts">
import { ref } from "vue";

/**
 * Reference of all base components in light and dark mode. New components are added here.
 */
const colors = [
  { name: "page", class: "bg-page" },
  { name: "surface", class: "bg-surface" },
  { name: "surface-muted", class: "bg-surface-muted" },
  { name: "line", class: "bg-line" },
  { name: "ink", class: "bg-ink" },
  { name: "ink-muted", class: "bg-ink-muted" },
  { name: "accent", class: "bg-accent" },
  { name: "accent-hover", class: "bg-accent-hover" },
];

const text = ref("");
const choice = ref<string | number | null>(null);
const checked = ref(true);
const options = [
  { value: 1, label: "Erste Möglichkeit" },
  { value: 2, label: "Zweite Möglichkeit" },
];
const modalOpen = ref(false);
const confirmOpen = ref(false);
</script>
