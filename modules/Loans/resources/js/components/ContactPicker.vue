<template>
  <div>
    <span class="block text-sm font-medium">{{ label }}</span>
    <!-- chosen contact with its completeness -->
    <div
      v-if="model"
      class="mt-1 flex flex-wrap items-center justify-between gap-2 rounded-md bg-surface-muted px-3 py-2 ring-1 ring-inset ring-line"
    >
      <div class="min-w-0">
        <p class="font-medium">{{ model.name }}</p>
        <p class="text-sm text-ink-muted">
          {{
            [model.affiliation, model.email, model.phone]
              .filter(Boolean)
              .join(" · ")
          }}
        </p>
        <p v-if="model.missing?.length" class="text-sm text-yellow-700">
          Es fehlen noch: {{ model.missing.join(", ") }}
        </p>
      </div>
      <div class="flex gap-2">
        <UiButton
          variant="secondary"
          size="sm"
          icon="pen"
          @click="editing = true"
          >Bearbeiten</UiButton
        >
        <UiButton variant="ghost" size="sm" icon="xmark" @click="model = null"
          >Andere</UiButton
        >
      </div>
    </div>
    <template v-else>
      <div class="mt-1 flex gap-2">
        <input
          v-model="search"
          type="search"
          autocomplete="off"
          :placeholder="placeholder"
          :aria-label="label"
          class="block w-full rounded-md border-0 bg-surface py-2 text-ink shadow-sm ring-1 ring-inset ring-line focus:ring-2 focus:ring-inset focus:ring-accent sm:text-sm"
          @input="find"
        />
        <UiButton variant="secondary" icon="plus" @click="creating = true"
          >Neu</UiButton
        >
      </div>
      <ul
        v-if="suggestions.length"
        class="mt-1 divide-y divide-line rounded-md ring-1 ring-line"
      >
        <li v-for="contact in suggestions" :key="contact.id">
          <button
            type="button"
            class="w-full px-3 py-2 text-left hover:bg-surface-muted"
            @click="choose(contact)"
          >
            <span class="font-medium">{{ contact.name }}</span>
            <span class="ml-2 text-sm text-ink-muted">{{
              [contact.affiliation, contact.email].filter(Boolean).join(" · ")
            }}</span>
          </button>
        </li>
      </ul>
    </template>
    <p v-if="hint && !error" class="mt-1 text-sm text-ink-muted">{{ hint }}</p>
    <p v-if="error" class="mt-1 text-sm text-red-600">{{ error }}</p>

    <LoansContactForm
      v-if="creating || editing"
      :contact="editing ? model : null"
      :name="search"
      :councils="councils"
      @close="creating = editing = false"
      @saved="choose"
    />
  </div>
</template>

<script setup lang="ts">
import { ref } from "vue";
import { getJson, type Contact } from "../http";
import LoansContactForm from "./ContactForm.vue";

const { url } = useSurface();

/**
 * Field for a contact: search by name or e-mail, or create a new one in the same dialog.
 */
withDefaults(
  defineProps<{
    label: string;
    councils: Option[];
    error?: string | null;
    hint?: string | null;
    placeholder?: string;
  }>(),
  { error: null, hint: null, placeholder: "Name oder E-Mail suchen" },
);

const model = defineModel<Contact | null>({ default: null });
const search = ref("");
const suggestions = ref<Contact[]>([]);
const creating = ref(false);
const editing = ref(false);

let timeout: ReturnType<typeof setTimeout> | null = null;
const find = () => {
  if (timeout) {
    clearTimeout(timeout);
  }
  const text = search.value.trim();
  if (!text) {
    suggestions.value = [];
    return;
  }
  timeout = setTimeout(async () => {
    suggestions.value =
      (await getJson<Contact[]>(
        url(`/loans/contacts/search?search=${encodeURIComponent(text)}`),
      )) ?? [];
  }, 250);
};

const choose = (contact: Contact) => {
  model.value = contact;
  suggestions.value = [];
  search.value = "";
  creating.value = editing.value = false;
};
</script>
