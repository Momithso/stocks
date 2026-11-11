<template>
  <div class="space-y-3">
    <UiInput
      v-model="search"
      label="Gegenstand suchen"
      type="search"
      placeholder="Name oder Artikelnummer"
      @update:model-value="find"
    />
    <ul
      v-if="results.length"
      class="divide-y divide-line rounded-md ring-1 ring-line"
    >
      <li
        v-for="item in results"
        :key="item.id"
        class="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
      >
        <div class="min-w-0">
          <p class="font-medium">
            <FontAwesomeIcon
              v-if="item.is_container"
              :icon="['fas', 'box']"
              class="mr-1 text-ink-muted"
            />
            {{ item.name }}
          </p>
          <p class="text-sm text-ink-muted">
            <span class="font-mono">{{ item.number }}</span>
            <template v-if="item.kind === 'bulk'">
              · {{ item.check.free }} von {{ item.quantity }}
              {{ item.unit }} frei</template
            >
            <template v-if="item.place"> · {{ item.place.code }}</template>
          </p>
          <p
            v-if="item.check.problem || item.check.conflicts.length"
            class="text-sm text-red-600"
          >
            {{
              item.check.problem ??
              `Gebucht in ${item.check.conflicts.map((loan) => loan.number).join(", ")}`
            }}
          </p>
          <p
            v-else-if="item.check.drafts.length"
            class="text-sm text-ink-muted"
          >
            Vorgemerkt in
            {{ item.check.drafts.map((loan) => loan.number).join(", ") }}
          </p>
        </div>
        <div class="flex items-center gap-2">
          <input
            v-if="item.kind === 'bulk'"
            v-model="quantities[item.id]"
            type="number"
            min="1"
            :max="item.quantity"
            inputmode="numeric"
            aria-label="Menge"
            class="w-20 rounded-md border-0 bg-surface py-1.5 text-ink ring-1 ring-inset ring-line"
          />
          <UiButton
            size="sm"
            icon="plus"
            :disabled="!!item.check.problem"
            @click="
              add({ item_id: item.id, quantity: quantities[item.id] ?? 1 })
            "
          >
            Hinzufügen
          </UiButton>
        </div>
      </li>
    </ul>
    <LoansScanInput
      placeholder="Oder Etikett scannen"
      @scan="add({ code: $event })"
    />
  </div>
</template>

<script setup lang="ts">
import { reactive, ref } from "vue";
import { router } from "@inertiajs/vue3";
import { getJson } from "../http";
import LoansScanInput from "./ScanInput.vue";

type Check = {
  problem: string | null;
  conflicts: { id: number; number: string }[];
  drafts: { id: number; number: string }[];
  free: number | null;
};
type Result = {
  id: number;
  number: string;
  name: string;
  kind: "single" | "bulk";
  quantity: number;
  unit: string | null;
  is_container: boolean;
  place: Place | null;
  check: Check;
};

/**
 * Adding positions to a loan: search with the availability in the time of the loan, or scan.
 */
const props = defineProps<{ loanId: number }>();

const search = ref("");
const results = ref<Result[]>([]);
const quantities = reactive<Record<number, number>>({});

let timeout: ReturnType<typeof setTimeout> | null = null;
const find = () => {
  if (timeout) {
    clearTimeout(timeout);
  }
  timeout = setTimeout(async () => {
    const text = search.value.trim();
    results.value = text
      ? ((await getJson<Result[]>(
          `/loans/search-items?loan=${props.loanId}&search=${encodeURIComponent(text)}`,
        )) ?? [])
      : [];
  }, 250);
};

const add = (data: { item_id?: number; code?: string; quantity?: number }) =>
  router.post(`/loans/${props.loanId}/positions`, data, {
    preserveScroll: true,
    onSuccess: () => {
      search.value = "";
      results.value = [];
    },
  });
</script>
