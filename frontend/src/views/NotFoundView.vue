<script setup lang="ts">
import { RouterLink } from 'vue-router';
</script>

<template>
  <main class="min-h-screen bg-cream-100 flex flex-col items-center justify-center p-4 text-center">
    <p class="font-display text-6xl font-bold text-primary-700">404</p>
    <p class="mt-2 text-primary-600">Halaman tidak ditemukan.</p>
    <RouterLink to="/dashboard" class="btn-primary mt-6">Kembali ke Dashboard</RouterLink>
  </main>
</template>
