<script setup lang="ts">
withDefaults(defineProps<{ open: boolean; title: string; description?: string }>(), {});
const emit = defineEmits<{ (e: 'close'): void }>();
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-primary-950/40 backdrop-blur-sm px-4 py-8 overflow-y-auto" @click.self="emit('close')">
      <div class="w-full max-w-md rounded-xl2 bg-white p-6 shadow-card-hover my-auto">
        <div class="flex items-start justify-between mb-4">
          <div>
            <h3 class="font-display text-lg font-semibold text-primary-800">{{ title }}</h3>
            <p v-if="description" class="mt-1 text-sm text-primary-500">{{ description }}</p>
          </div>
          <button class="text-primary-400 hover:text-primary-700 -mt-1 -mr-1 p-1" aria-label="Tutup" @click="emit('close')">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        <slot />
      </div>
    </div>
  </Teleport>
</template>
