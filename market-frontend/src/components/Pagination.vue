<script setup lang="ts">
const props = defineProps<{ page: number; perPage: number; total: number }>();
const emit = defineEmits<{ (e: 'update:page', page: number): void }>();

function totalPages() {
  return Math.max(1, Math.ceil(props.total / props.perPage));
}
</script>

<template>
  <div v-if="total > perPage" class="flex items-center justify-between border-t border-primary-100 pt-4 mt-4">
    <p class="text-xs text-primary-500">
      Halaman {{ page }} dari {{ totalPages() }} &middot; {{ total }} total data
    </p>
    <div class="flex gap-2">
      <button
        class="btn-secondary !px-3 !py-1.5 text-xs"
        :disabled="page <= 1"
        @click="emit('update:page', page - 1)"
      >
        Sebelumnya
      </button>
      <button
        class="btn-secondary !px-3 !py-1.5 text-xs"
        :disabled="page >= totalPages()"
        @click="emit('update:page', page + 1)"
      >
        Berikutnya
      </button>
    </div>
  </div>
</template>
