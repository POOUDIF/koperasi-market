<script setup lang="ts">
withDefaults(
  defineProps<{
    icon?: string;
    title: string;
    description?: string;
  }>(),
  { icon: '📭' },
);
</script>

<template>
  <div class="flex flex-col items-center justify-center rounded-xl2 border border-dashed border-primary-200 bg-cream-50 px-6 py-14 text-center">
    <span class="text-4xl mb-3">{{ icon }}</span>
    <p class="font-display text-lg font-semibold text-primary-800">{{ title }}</p>
    <p v-if="description" class="mt-1 max-w-sm text-sm text-primary-500">{{ description }}</p>
    <div v-if="$slots.action" class="mt-5">
      <slot name="action" />
    </div>
  </div>
</template>
