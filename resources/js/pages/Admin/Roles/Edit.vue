<template>
  <UiPageHeader :title="role ? `Rolle ${role.name}` : 'Neue Rolle'">
    <UiButton href="/admin/roles" variant="secondary">Zurück</UiButton>
  </UiPageHeader>

  <form class="space-y-6" @submit.prevent="submit">
    <UiCard>
      <div class="grid gap-5 sm:grid-cols-2">
        <UiInput
          v-model="form.name"
          label="Name"
          required
          :error="form.errors.name"
        />
        <UiInput
          v-model="form.description"
          label="Beschreibung"
          :error="form.errors.description"
        />
      </div>
    </UiCard>

    <UiAlert
      v-if="form.errors.permissions"
      type="error"
      :message="form.errors.permissions"
    />

    <UiCard
      v-for="group in permissionGroups"
      :key="group.group"
      :title="group.group"
      :description="group.module ? 'Modul' : undefined"
    >
      <div class="grid gap-4 sm:grid-cols-2">
        <UiCheckbox
          v-for="(description, name) in group.permissions"
          :key="name"
          v-model="form.permissions"
          :value="name"
          :label="description"
          :description="
            ownPermissions.includes(name) ? name : `${name} · fehlt dir selbst`
          "
          :disabled="!ownPermissions.includes(name)"
        />
      </div>
    </UiCard>

    <div class="flex justify-end">
      <UiButton type="submit" :disabled="form.processing">Speichern</UiButton>
    </div>
  </form>
</template>

<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";

/**
 * Create or change a role. Permissions the user does not have himself are disabled; the server
 * checks the same rule (App\Support\Permissions\GrantCheck).
 */
const props = defineProps<{
  role: { id: number; name: string; description: string | null } | null;
  rolePermissions: string[];
  permissionGroups: {
    group: string;
    module: string | null;
    permissions: Record<string, string>;
  }[];
  ownPermissions: string[];
}>();

const form = useForm({
  name: props.role?.name ?? "",
  description: props.role?.description ?? "",
  permissions: [...props.rolePermissions],
});

const submit = () =>
  props.role
    ? form.put(`/admin/roles/${props.role.id}`)
    : form.post("/admin/roles");
</script>
