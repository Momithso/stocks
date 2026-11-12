<template>
  <UiPageHeader
    title="Kiosk-Geräte"
    description="Ein Tablet wird zum Kiosk, wenn es einmal die Kopplungs-Adresse öffnet."
  />

  <UiCard title="Sitzung" class="mb-6">
    <form class="flex flex-wrap items-end gap-6" @submit.prevent="saveSettings">
      <UiInput
        v-model="settingsForm.session_timeout"
        type="number"
        min="30"
        max="3600"
        label="Sperre nach Sekunden ohne Bedienung"
        class="w-64"
        required
        :error="settingsForm.errors.session_timeout"
      />
      <UiCheckbox
        v-model="settingsForm.lock_after_task"
        label="Nach jedem Vorgang sperren"
        description="Sonst geht der Kiosk nach dem Abschluss zurück zur Startseite und bleibt entsperrt, bis die Zeit abläuft."
        class="flex-1"
      />
      <UiButton type="submit" icon="check" :disabled="settingsForm.processing">
        Speichern
      </UiButton>
    </form>
  </UiCard>

  <UiCard title="Neues Gerät" class="mb-6">
    <form class="flex flex-wrap items-end gap-3" @submit.prevent="create">
      <UiInput
        v-model="form.name"
        label="Name"
        placeholder="Tablet Lager"
        class="min-w-[16rem] flex-1"
        required
        :error="form.errors.name"
      />
      <UiSelect
        v-model="form.platform"
        label="Plattform"
        :options="platforms"
        :placeholder="null"
        class="w-48"
      />
      <UiButton type="submit" icon="plus" :disabled="form.processing"
        >Anlegen</UiButton
      >
    </form>
  </UiCard>

  <UiCard flush>
    <UiEmptyState
      v-if="!devices.length"
      icon="tablet-screen-button"
      title="Noch kein Gerät gekoppelt"
    />
    <ul v-else class="divide-y divide-line">
      <li
        v-for="device in devices"
        :key="device.id"
        class="flex flex-wrap gap-6 px-4 py-4 sm:px-6"
      >
        <!-- qr code as svg from the server (bacon/bacon-qr-code), contains no user input;
             the svg has a fixed size of its own and is scaled to the box -->
        <div
          class="h-32 w-32 shrink-0 overflow-hidden rounded bg-white p-1 [&>svg]:h-full [&>svg]:w-full"
          v-html="device.pairing_qr_code"
        />
        <div class="min-w-0 flex-1 space-y-2">
          <form
            v-if="editing?.id === device.id"
            class="flex flex-wrap items-end gap-2"
            @submit.prevent="update"
          >
            <UiInput
              v-model="editForm.name"
              label="Name"
              required
              :error="editForm.errors.name"
            />
            <UiSelect
              v-model="editForm.platform"
              label="Plattform"
              :options="platforms"
              :placeholder="null"
            />
            <UiButton type="submit" size="sm" icon="check">Speichern</UiButton>
            <UiButton variant="ghost" size="sm" @click="editing = null">
              Abbrechen
            </UiButton>
          </form>
          <p v-else class="font-medium">
            {{ device.name }}
            <UiBadge class="ml-2">{{ device.platform_label }}</UiBadge>
          </p>
          <p class="text-sm text-ink-muted">
            Zuletzt genutzt: <UiDateTime :value="device.last_used_at" />
          </p>
          <p class="break-all text-xs text-ink-muted">
            {{ device.pairing_url }}
          </p>
          <div class="flex flex-wrap gap-2">
            <UiButton
              variant="secondary"
              size="sm"
              icon="pen"
              @click="edit(device)"
            >
              Bearbeiten
            </UiButton>
            <UiButton
              variant="secondary"
              size="sm"
              icon="rotate"
              :href="`/admin/kiosk-devices/${device.id}/regenerate-token`"
              method="post"
            >
              Neu koppeln
            </UiButton>
            <UiButton
              variant="ghost"
              size="sm"
              icon="trash"
              @click="deleting = device"
            >
              Löschen
            </UiButton>
          </div>
        </div>
      </li>
    </ul>
  </UiCard>

  <UiConfirmModal
    v-if="deleting"
    title="Gerät löschen"
    :message="`${deleting.name} wird sofort gesperrt.`"
    @close="deleting = null"
    @confirm="destroy"
  />
</template>

<script setup lang="ts">
import { ref } from "vue";
import { router, useForm } from "@inertiajs/vue3";

/**
 * Kiosk devices with their pairing code and platform, and the settings of the kiosk session.
 */
type Device = {
  id: number;
  name: string;
  platform: string;
  platform_label: string;
  last_used_at: string | null;
  pairing_url: string;
  pairing_qr_code: string;
};

const props = defineProps<{
  devices: Device[];
  platforms: { value: string; label: string }[];
  settings: { session_timeout: number; lock_after_task: boolean };
}>();

const settingsForm = useForm({ ...props.settings });
const saveSettings = () =>
  settingsForm.put("/admin/kiosk-settings", { preserveScroll: true });

const form = useForm({ name: "", platform: "other" });
const create = () =>
  form.post("/admin/kiosk-devices", {
    preserveScroll: true,
    onSuccess: () => form.reset(),
  });

const editing = ref<Device | null>(null);
const editForm = useForm({ name: "", platform: "other" });
const edit = (device: Device) => {
  editing.value = device;
  editForm.defaults({ name: device.name, platform: device.platform });
  editForm.reset();
};
const update = () =>
  editForm.put(`/admin/kiosk-devices/${editing.value!.id}`, {
    preserveScroll: true,
    onSuccess: () => (editing.value = null),
  });

const deleting = ref<Device | null>(null);
const destroy = () =>
  router.delete(`/admin/kiosk-devices/${deleting.value!.id}`, {
    preserveScroll: true,
    onFinish: () => (deleting.value = null),
  });
</script>
