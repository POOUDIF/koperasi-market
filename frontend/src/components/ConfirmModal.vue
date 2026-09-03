<script setup lang="ts">
withDefaults(
  defineProps<{
    open: boolean;
    title: string;
    description?: string;
    confirmLabel?: string;
    tone?: 'primary' | 'danger';
    loading?: boolean;
  }>(),
  { confirmLabel: 'Konfirmasi', tone: 'primary', loading: false },
);
const emit = defineEmits<{ (e: 'confirm'): void; (e: 'cancel'): void }>();
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-primary-950/40 backdrop-blur-sm px-4" @click.self="emit('cancel')">
      <div class="w-full max-w-sm rounded-xl2 bg-white p-6 shadow-card-hover">
        <h3 class="font-display text-lg font-semibold text-primary-800">{{ title }}</h3>
        <p v-if="description" class="mt-2 text-sm text-primary-600">{{ description }}</p>
        <div class="mt-6 flex justify-end gap-3">
          <button class="btn-secondary" :disabled="loading" @click="emit('cancel')">Batal</button>
          <button
            :class="tone === 'danger' ? 'btn-danger' : 'btn-primary'"
            :disabled="loading"
            @click="emit('confirm')"
          >
            <span v-if="loading" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/40 border-t-white" />
            {{ confirmLabel }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
