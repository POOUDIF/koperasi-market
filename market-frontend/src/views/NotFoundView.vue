<script setup lang="ts">
import { RouterLink } from 'vue-router';
import MarketShell from '@/components/MarketShell.vue';
</script>

<template>
  <MarketShell>
    <div class="py-16 text-center">
      <p class="font-display text-6xl font-bold text-primary-700">404</p>
      <p class="mt-2 text-primary-600">Halaman tidak ditemukan.</p>
      <RouterLink to="/" class="btn-primary mt-6">Kembali Belanja</RouterLink>
    </div>
  </MarketShell>
</template>
