<template>
  <UiPageHeader title="Protokoll" description="Wer hat wann was geändert." />

  <UiCard class="mb-6">
    <form
      class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6"
      @submit.prevent="apply"
    >
      <UiSelect v-model="form.user_id" label="Person" :options="userOptions" />
      <UiSelect
        v-model="form.subject_type"
        label="Art"
        :options="subjectTypes"
      />
      <UiSelect v-model="form.action" label="Aktion" :options="actions" />
      <UiInput v-model="form.subject_id" label="ID" type="number" />
      <UiInput v-model="form.from" label="Von" type="date" />
      <UiInput v-model="form.to" label="Bis" type="date" />
      <div class="flex gap-2 sm:col-span-3 lg:col-span-6">
        <UiButton type="submit">Filtern</UiButton>
        <UiButton variant="ghost" href="/admin/audit-log"
          >Zurücksetzen</UiButton
        >
      </div>
    </form>
  </UiCard>

  <UiCard flush>
    <UiEmptyState
      v-if="!audits.data.length"
      icon="clock-rotate-left"
      title="Keine Einträge"
    />
    <div v-else class="overflow-x-auto">
      <table class="min-w-full divide-y divide-line text-sm">
        <thead class="bg-surface-muted">
          <tr>
            <th class="px-4 py-3 text-left font-semibold sm:px-6">Zeit</th>
            <th class="px-4 py-3 text-left font-semibold">Person</th>
            <th class="px-4 py-3 text-left font-semibold">Aktion</th>
            <th class="px-4 py-3 text-left font-semibold">Betrifft</th>
            <th class="px-4 py-3 text-left font-semibold sm:pr-6">
              Änderungen
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-line align-top">
          <tr v-for="entry in audits.data" :key="entry.id">
            <td class="whitespace-nowrap px-4 py-3 sm:px-6">
              <UiDateTime :value="entry.created_at" />
            </td>
            <td class="whitespace-nowrap px-4 py-3">
              {{ entry.actor?.name ?? "System" }}
            </td>
            <td class="whitespace-nowrap px-4 py-3">
              <UiBadge>{{ entry.action_label }}</UiBadge>
            </td>
            <td class="px-4 py-3">
              <p class="text-xs uppercase tracking-wide text-ink-muted">
                {{ entry.subject_type_label }}
              </p>
              <p class="font-medium">{{ entry.subject_label }}</p>
            </td>
            <td class="px-4 py-3 sm:pr-6">
              <dl v-if="entry.changes.length" class="space-y-1">
                <div
                  v-for="change in entry.changes"
                  :key="change.field"
                  class="flex flex-wrap gap-x-2"
                >
                  <dt class="font-medium">{{ change.label }}:</dt>
                  <dd class="break-all">
                    <template v-if="showOld(entry.action)">
                      <span
                        class="text-red-700 line-through dark:text-red-400"
                        >{{ change.old ?? "—" }}</span
                      >
                      &rarr;
                    </template>
                    <span>{{
                      (showOld(entry.action)
                        ? change.new
                        : (change.new ?? change.old)) ?? "—"
                    }}</span>
                  </dd>
                </div>
              </dl>
              <span v-else class="text-ink-muted">—</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <UiPagination :paginator="audits" />
  </UiCard>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { router, useForm } from "@inertiajs/vue3";

type Entry = {
  id: number;
  created_at: string | null;
  actor: { id: number; name: string } | null;
  action: string;
  action_label: string;
  subject_type_label: string;
  subject_label: string;
  changes: {
    field: string;
    label: string;
    old: string | null;
    new: string | null;
  }[];
};

const props = defineProps<{
  audits: Pagination.Paginator<Entry>;
  filters: Record<string, string | number | null>;
  users: { id: number; firstname: string; lastname: string }[];
  subjectTypes: Option[];
  actions: Option[];
}>();

const userOptions = computed(() =>
  props.users.map((user) => ({
    value: user.id,
    label: `${user.firstname} ${user.lastname}`,
  })),
);

const form = useForm({
  user_id: props.filters.user_id ?? null,
  subject_type: props.filters.subject_type ?? null,
  action: props.filters.action ?? null,
  subject_id: props.filters.subject_id ?? null,
  from: props.filters.from ?? null,
  to: props.filters.to ?? null,
});

const apply = () => {
  const query = Object.fromEntries(
    Object.entries(form.data()).filter(
      ([, value]) => value !== null && value !== "",
    ),
  );
  router.get("/admin/audit-log", query, { preserveState: true });
};

// created and deleted entries only have one side of the change
const showOld = (action: string) =>
  !["created", "deleted", "restored"].includes(action);
</script>
