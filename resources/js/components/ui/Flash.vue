<template>
  <div v-if="messages.length || label" class="mb-6 space-y-3">
    <UiAlert
      v-for="message in messages"
      :key="message.type"
      :type="message.type"
      :message="message.text"
    />
    <UiAlert v-if="label && can('labels.print')" type="info">
      {{ label.number }} hat eine neue Nummer.
      <InertiaLink
        :href="`/labels/print?items=${label.id}`"
        class="ml-1 font-bold underline"
      >
        Etikett drucken
      </InertiaLink>
    </UiAlert>
  </div>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { Link as InertiaLink, usePage } from "@inertiajs/vue3";

/**
 * Flash messages of the server (session "success", "error", "warning", "info"), and the offer to
 * print the label of an item with a new number (session "label").
 */
const page = usePage<SharedProps>();
const { can } = usePermissions();

const label = computed(() => page.props.flash?.label ?? null);

const messages = computed(() =>
  (["error", "warning", "success", "info"] as const)
    .filter((type) => page.props.flash?.[type])
    .map((type) => ({ type, text: page.props.flash[type] as string })),
);
</script>
