<template>
  <UiPageHeader
    title="Konten"
    description="Neue Konten entstehen nur per Einladung."
  >
    <UiButton v-if="can('users.invite')" icon="plus" @click="inviteOpen = true">
      Einladen
    </UiButton>
  </UiPageHeader>

  <UiCard
    v-if="invitations.length"
    title="Offene Einladungen"
    flush
    class="mb-6"
  >
    <ul class="divide-y divide-line">
      <li
        v-for="invitation in invitations"
        :key="invitation.id"
        class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6"
      >
        <div>
          <p class="font-medium">{{ invitation.email }}</p>
          <p class="text-sm text-ink-muted">
            gültig bis <UiDateTime :value="invitation.expires_at" />
            <template v-if="invitation.roles.length">
              · {{ invitation.roles.map((role) => role.name).join(", ") }}
            </template>
          </p>
        </div>
        <UiButton
          variant="ghost"
          size="sm"
          icon="xmark"
          :href="`/admin/invitations/${invitation.id}`"
          method="delete"
        >
          Zurückziehen
        </UiButton>
      </li>
    </ul>
  </UiCard>

  <UiCard flush>
    <UiTable ref="table" data="users" :columns="columns" searchable>
      <template #cell-name="{ row }">
        <span class="font-medium">{{ row.firstname }} {{ row.lastname }}</span>
        <UiBadge v-if="row.is_disabled" variant="danger" class="ml-2"
          >deaktiviert</UiBadge
        >
      </template>
      <template #cell-roles="{ row }">
        <div class="flex flex-wrap gap-1">
          <UiBadge v-for="role in row.roles" :key="role.id" variant="accent">{{
            role.name
          }}</UiBadge>
        </div>
      </template>
      <template #cell-last_login_at="{ row }">
        <UiDateTime :value="row.last_login_at" />
      </template>
      <template v-if="can('users.manage')" #actions="{ row }">
        <UiButton
          v-if="row.id !== page.props.auth!.user.id"
          variant="secondary"
          size="sm"
          icon="pen"
          @click="editing = row"
        >
          Bearbeiten
        </UiButton>
      </template>
    </UiTable>
  </UiCard>

  <!-- invite -->
  <UiModal v-if="inviteOpen" title="Konto einladen" @close="inviteOpen = false">
    <form class="space-y-5" @submit.prevent="invite">
      <UiInput
        v-model="inviteForm.email"
        label="E-Mail-Adresse"
        type="email"
        required
        :error="inviteForm.errors.email"
      />
      <fieldset v-if="can('roles.manage')" class="space-y-2">
        <legend class="text-sm font-medium">Rollen</legend>
        <UiCheckbox
          v-for="role in roles"
          :key="role.id"
          v-model="inviteForm.roles"
          :value="role.id"
          :label="role.name"
          :disabled="!role.grantable"
        />
        <p v-if="inviteForm.errors.roles" class="text-sm text-red-600">
          {{ inviteForm.errors.roles }}
        </p>
      </fieldset>
      <UiButton
        type="submit"
        class="w-full"
        icon="envelope"
        :disabled="inviteForm.processing"
      >
        Einladung schicken
      </UiButton>
    </form>
  </UiModal>

  <!-- edit -->
  <UiModal
    v-if="editing"
    :title="`${editing.firstname} ${editing.lastname}`"
    @close="editing = null"
  >
    <form class="space-y-5" @submit.prevent="save">
      <p class="text-sm text-ink-muted">{{ editing.email }}</p>
      <fieldset v-if="can('roles.manage')" class="space-y-2">
        <legend class="text-sm font-medium">Rollen</legend>
        <UiCheckbox
          v-for="role in roles"
          :key="role.id"
          v-model="editForm.roles"
          :value="role.id"
          :label="role.name"
          :disabled="!role.grantable"
        />
        <p v-if="editForm.errors.roles" class="text-sm text-red-600">
          {{ editForm.errors.roles }}
        </p>
      </fieldset>
      <UiCheckbox
        v-model="editForm.is_disabled"
        label="Deaktiviert"
        description="Das Konto kann sich nicht mehr anmelden."
      />
      <UiAlert v-if="pageErrors.length" type="error">{{
        pageErrors.join(" ")
      }}</UiAlert>
      <div class="flex flex-col gap-3 sm:flex-row">
        <UiButton type="submit" class="flex-1" :disabled="editForm.processing"
          >Speichern</UiButton
        >
        <UiButton
          v-if="editing.has_pin"
          variant="secondary"
          :href="`/admin/users/${editing.id}/reset-pin`"
          method="post"
          @click="editing = null"
        >
          PIN zurücksetzen
        </UiButton>
        <UiButton
          v-if="can('users.delete')"
          variant="danger"
          icon="trash"
          @click="deleting = editing"
        >
          Löschen
        </UiButton>
      </div>
    </form>
  </UiModal>

  <UiConfirmModal
    v-if="deleting"
    title="Konto löschen"
    :message="`Das Konto von ${deleting.firstname} ${deleting.lastname} wird gelöscht. Das lässt sich nicht rückgängig machen. Deaktivieren ist meist die bessere Wahl.`"
    @close="deleting = null"
    @confirm="destroy"
  />
</template>

<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { router, useForm, usePage } from "@inertiajs/vue3";

type UserRow = {
  id: number;
  firstname: string;
  lastname: string;
  email: string;
  roles: { id: number; name: string }[];
  is_disabled: boolean;
  has_pin: boolean;
  last_login_at: string | null;
};

defineProps<{
  roles: { id: number; name: string; grantable: boolean }[];
  invitations: {
    id: number;
    email: string;
    expires_at: string;
    roles: { id: number; name: string }[];
  }[];
}>();

const page = usePage<SharedProps>();
const { can } = usePermissions();
const table = ref<{ refresh: () => void } | null>(null);

const columns: TableColumn[] = [
  { name: "name", header: "Name", sortKey: "lastname" },
  {
    name: "email",
    header: "E-Mail",
    value: (row) => row.email,
    sortKey: "email",
  },
  { name: "roles", header: "Rollen", sortKey: "roles" },
  {
    name: "last_login_at",
    header: "Letzte Anmeldung",
    sortKey: "last_login_at",
  },
];

// errors that belong to no field, e.g. "Du kannst dieses Konto nicht bearbeiten."
const pageErrors = computed(() => Object.values(page.props.errors ?? {}));

const inviteOpen = ref(false);
const inviteForm = useForm({ email: "", roles: [] as number[] });
const invite = () =>
  inviteForm.post("/admin/users/invite", {
    preserveScroll: true,
    onSuccess: () => {
      inviteOpen.value = false;
      inviteForm.reset();
    },
  });

const editing = ref<UserRow | null>(null);
const editForm = useForm({ roles: [] as number[], is_disabled: false });
watch(editing, (user) => {
  if (user) {
    editForm.defaults({
      roles: user.roles.map((role) => role.id),
      is_disabled: user.is_disabled,
    });
    editForm.reset();
    editForm.clearErrors();
  }
});
const save = () =>
  editForm.put(`/admin/users/${editing.value!.id}`, {
    preserveScroll: true,
    onSuccess: () => {
      editing.value = null;
      table.value?.refresh();
    },
  });

const deleting = ref<UserRow | null>(null);
const destroy = () =>
  router.delete(`/admin/users/${deleting.value!.id}`, {
    preserveScroll: true,
    onFinish: () => {
      deleting.value = null;
      editing.value = null;
      table.value?.refresh();
    },
  });
</script>
