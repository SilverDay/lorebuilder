<script setup>
import { useToastStore } from '@/stores/toast.js'

const toast = useToastStore()
</script>

<template>
  <Teleport to="body">
    <div class="toast-container" aria-live="polite">
      <TransitionGroup name="toast">
        <div
          v-for="t in toast.toasts"
          :key="t.id"
          class="toast"
          :class="`toast-${t.type}`"
          role="status"
        >
          {{ t.message }}
          <button class="toast-close" aria-label="Dismiss" @click="toast.remove(t.id)">×</button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>
