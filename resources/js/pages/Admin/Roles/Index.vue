<template>
  <UiPageHeader
    title="Rollen"
    description="Rollen bündeln Rechte. Du kannst nur Rechte vergeben, die du selbst hast."
  >
    <UiButton href="/admin/roles/create" icon="plus">Neue Rolle</UiButton>
  </UiPageHeader>

  <UiCard flush>
    <ul class="divide-y divide-line">
      <li
        v-for="role in roles"
        :key="role.id"
        class="flex flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6"
      >
        <div>
          <p class="flex items-center gap-2 font-medium">
            {{ role.name }}
            <UiBadge v-if="role.is_system" variant="accent">System</UiBadge>
          </p>
          <p class="text-sm text-ink-muted">
            {{ role.description ?? "Keine Beschreibung" }} ·
            {{ role.permissions_count }} Rechte · {{ role.users_count }} Konten
          </p>
        </div>
        <div class="flex gap-2">
          <UiButton
            v-if="role.editable"
            :href="`/admin/roles/${role.id}/edit`"
            variant="secondary"
            size="sm"
            icon="pen"
          >
            Bearbeiten
          </UiButton>
          <UiButton
            v-if="role.editable && role.users_count === 0"
            variant="ghost"
            size="sm"
            icon="trash"
            @click="deleting = role"
          >
            Löschen
          </UiButton>
        </div>
      </li>
    </ul>
  </UiCard>

  <UiConfirmModal
    v-if="deleting"
    title="Rolle löschen"
    :message="`Die Rolle ${deleting.name} wird gelöscht.`"
    @close="deleting = null"
    @confirm="destroy"
  />
</template>

<script setup lang="ts">
import { ref } from "vue";
import { router } from "@inertiajs/vue3";

type RoleRow = {
  id: number;
  name: string;
  description: string | null;
  is_system: boolean;
  users_count: number;
  permissions_count: number;
  editable: boolean;
};

defineProps<{ roles: RoleRow[] }>();

const deleting = ref<RoleRow | null>(null);
const destroy = () =>
  router.delete(`/admin/roles/${deleting.value!.id}`, {
    onFinish: () => (deleting.value = null),
  });
</script>
