<template>
  <UiPageHeader
    title="Kiosk-Geräte"
    description="Ein Tablet wird zum Kiosk, wenn es einmal die Kopplungs-Adresse öffnet."
  />

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
        <!-- qr code as svg from the server (bacon/bacon-qr-code), contains no user input -->
        <div
          class="h-32 w-32 shrink-0 rounded bg-white p-1"
          v-html="device.pairing_qr_code"
        />
        <div class="min-w-0 flex-1 space-y-2">
          <p class="font-medium">{{ device.name }}</p>
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

type Device = {
  id: number;
  name: string;
  last_used_at: string | null;
  pairing_url: string;
  pairing_qr_code: string;
};

defineProps<{ devices: Device[] }>();

const form = useForm({ name: "" });
const create = () =>
  form.post("/admin/kiosk-devices", {
    preserveScroll: true,
    onSuccess: () => form.reset(),
  });

const deleting = ref<Device | null>(null);
const destroy = () =>
  router.delete(`/admin/kiosk-devices/${deleting.value!.id}`, {
    preserveScroll: true,
    onFinish: () => (deleting.value = null),
  });
</script>
