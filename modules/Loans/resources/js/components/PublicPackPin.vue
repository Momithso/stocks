<template>
  <section class="rounded-lg bg-surface p-5 shadow ring-1 ring-line/50">
    <h2 class="font-semibold">Kontrolle für eine Buchung</h2>

    <form v-if="!unlocked" class="mt-3 space-y-3" @submit.prevent="unlock">
      <p class="text-sm text-ink-muted">
        Du packst die Box für eine Buchung? Mit der Pack-PIN aus der
        Buchungsbestätigung bestätigst du, was du abgehakt hast.
      </p>
      <UiInput
        v-model="pinForm.pin"
        label="Pack-PIN"
        inputmode="numeric"
        autocomplete="off"
        :error="pinForm.errors.pin"
      />
      <UiButton type="submit" class="w-full" :disabled="pinForm.processing"
        >Weiter</UiButton
      >
    </form>

    <div v-else class="mt-3 space-y-3">
      <p class="text-sm">
        Buchung <strong>{{ unlocked.loan }}</strong
        >, {{ formatDateTime(unlocked.pickup_at) }} bis
        {{ formatDateTime(unlocked.return_at) }}.
      </p>
      <ul
        v-if="unlocked.checks.length"
        class="space-y-1 text-sm text-ink-muted"
      >
        <li v-for="check in unlocked.checks" :key="check.checked_at">
          Kontrolliert von {{ check.name }} am
          {{ formatDateTime(check.checked_at) }} ({{ check.ticked }} von
          {{ check.total }} abgehakt)
        </li>
      </ul>
      <form class="space-y-3" @submit.prevent="confirm">
        <label class="block">
          <span class="block text-sm font-medium">Dein Name</span>
          <!-- names used for this loan so far to choose from -->
          <input
            v-model="checkForm.name"
            list="pack-pin-names"
            autocomplete="name"
            required
            class="mt-1 block w-full rounded-md border-0 bg-surface py-2 text-ink shadow-sm ring-1 ring-inset ring-line focus:ring-2 focus:ring-inset focus:ring-accent"
          />
          <span
            v-if="checkForm.errors.name"
            class="mt-1 block text-sm text-red-600"
            >{{ checkForm.errors.name }}</span
          >
        </label>
        <datalist id="pack-pin-names">
          <option v-for="name in unlocked.names" :key="name" :value="name" />
        </datalist>
        <p class="text-sm">
          {{ checked.length }} von {{ total }} oben abgehakt.
        </p>
        <UiButton
          type="submit"
          icon="check"
          class="w-full"
          :disabled="checkForm.processing"
        >
          Kontrolle bestätigen
        </UiButton>
      </form>
    </div>
  </section>
</template>

<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import { formatDateTime } from "../http";

/**
 * Pack pin on the public page of a box: after the right pin the number and time of the loan, then
 * a check with name and the ticks of the list above is stored at the box of the loan.
 */
const props = defineProps<{
  number: string;
  unlocked: {
    loan: string;
    pickup_at: string;
    return_at: string;
    names: string[];
    checks: {
      name: string;
      checked_at: string;
      ticked: number;
      total: number;
    }[];
  } | null;
  // ticked off numbers and rows of the list of the page (resources/js/pages/Public/Show.vue)
  checked: string[];
  total: number;
}>();

const url = `/s/${encodeURIComponent(props.number)}`;
const pinForm = useForm({ pin: "" });
const unlock = () => pinForm.post(`${url}/pack-pin`, { preserveScroll: true });

const checkForm = useForm({
  name: props.unlocked?.names[0] ?? "",
  ticks: [] as string[],
  total: 0,
});
const confirm = () =>
  checkForm
    .transform((data) => ({
      ...data,
      ticks: props.checked,
      total: props.total,
    }))
    .post(`${url}/box-check`, { preserveScroll: true });
</script>
