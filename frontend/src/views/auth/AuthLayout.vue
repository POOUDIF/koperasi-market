<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
</script>

<template>
  <main class="min-h-screen bg-gradient-to-br from-primary-800 via-primary-700 to-secondary-800 flex items-center justify-center p-4 relative overflow-hidden">
    <div class="pointer-events-none absolute inset-0 opacity-[0.07]" style="background-image: radial-gradient(circle at 20% 20%, #E6B94D 0, transparent 45%), radial-gradient(circle at 85% 75%, #E6B94D 0, transparent 40%);" />

    <div class="w-full max-w-md relative">
      <div class="text-center mb-8">
        <div class="inline-flex mb-4 drop-shadow-lg">
          <AppLogo :size="72" />
        </div>
        <h1 class="font-display text-2xl font-bold text-white">Jawa Dwipa Cooperative</h1>
        <p class="text-sm text-primary-100/80 mt-1 tracking-wide">TOGETHER &middot; IN ACTION &middot; FOR PROSPERITY</p>
      </div>

      <div class="bg-white rounded-2xl shadow-2xl p-8">
        <slot />
      </div>

      <p class="mt-6 text-center text-xs text-primary-100/70">
        &copy; {{ new Date().getFullYear() }} Koperasi Digital Syariah Jawa Dwipa. Semua hak cipta dilindungi.
      </p>
    </div>
  </main>
</template>
