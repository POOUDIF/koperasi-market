<script setup lang="ts">
withDefaults(defineProps<{ rows?: number }>(), { rows: 3 });
</script>

<template>
  <div class="space-y-3" role="status" aria-label="Memuat">
    <div v-for="i in rows" :key="i" class="h-16 w-full animate-pulse rounded-xl2 bg-primary-100/60" />
  </div>
</template>
