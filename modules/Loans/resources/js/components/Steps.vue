<template>
  <!-- the steps of a task as a line on top: done, current, still to come -->
  <nav aria-label="Schritte" class="mb-6">
    <ol class="flex items-start">
      <li
        v-for="(step, index) in steps"
        :key="step"
        class="relative flex flex-1 flex-col items-center"
      >
        <!-- line to the next step -->
        <span
          v-if="index < steps.length - 1"
          :class="[
            'absolute left-1/2 top-5 h-1 w-full',
            index < current ? 'bg-accent' : 'bg-line',
          ]"
          aria-hidden="true"
        />
        <button
          type="button"
          :disabled="index > reachable || index === current"
          :aria-current="index === current ? 'step' : undefined"
          :class="[
            'relative z-10 flex h-10 w-10 items-center justify-center rounded-full text-base font-bold ring-4 ring-surface',
            index < current
              ? 'bg-accent text-white'
              : index === current
                ? 'bg-accent text-white shadow-lg'
                : index <= reachable
                  ? 'bg-surface-muted text-ink'
                  : 'bg-surface-muted text-ink-muted',
          ]"
          @click="emit('select', index)"
        >
          <FontAwesomeIcon v-if="index < current" :icon="['fas', 'check']" />
          <template v-else>{{ index + 1 }}</template>
        </button>
        <span
          :class="[
            'mt-2 px-1 text-center text-xs sm:text-sm',
            index === current ? 'font-semibold text-ink' : 'text-ink-muted',
          ]"
        >
          {{ step }}
        </span>
      </li>
    </ol>
  </nav>
</template>

<script setup lang="ts">
/**
 * Step line of issue and return: steps before the current one are done and can be opened again,
 * later steps only up to "reachable" (e.g. the signatures once everything is ticked off).
 */
defineProps<{ steps: string[]; current: number; reachable: number }>();
const emit = defineEmits<{ select: [index: number] }>();
</script>
