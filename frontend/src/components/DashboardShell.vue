<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import AppLogo from '@/components/AppLogo.vue';
import { useAuthStore } from '@/stores/auth';
import { useLogout } from '@/composables/useAuth';
import { roleLabel } from '@/lib/utils';

const route = useRoute();
const authStore = useAuthStore();
const logoutMutation = useLogout();
const mobileNavOpen = ref(false);

const memberLinks = [
  { to: '/dashboard', label: 'Dashboard', icon: '🏠' },
  { to: '/dashboard/savings', label: 'Simpanan', icon: '🏦' },
  { to: '/dashboard/financing', label: 'Pembiayaan', icon: '📋' },
  { to: '/dashboard/gold', label: 'Emas Digital', icon: '🥇' },
  { to: '/dashboard/kyc', label: 'Profil KYC', icon: '🪪' },
];

const adminLinks = [
  { to: '/dashboard/admin', label: 'Dashboard Admin', icon: '🛡️' },
  { to: '/dashboard/admin/deposits', label: 'Verifikasi Setoran', icon: '📥' },
  { to: '/dashboard/admin/withdrawals', label: 'Verifikasi Penarikan', icon: '📤' },
  { to: '/dashboard/admin/financing', label: 'Review Pembiayaan', icon: '✅' },
  { to: '/dashboard/admin/gold-price', label: 'Harga Emas', icon: '💰' },
  { to: '/dashboard/admin/users', label: 'Manajemen Anggota', icon: '👥' },
  { to: '/dashboard/admin/transactions', label: 'Riwayat Transaksi', icon: '📊' },
];

function isActive(to: string) {
  return to === '/dashboard' ? route.path === to : route.path.startsWith(to);
}
</script>

<template>
  <div class="min-h-screen bg-cream-100">
    <!-- Sidebar desktop -->
    <aside class="hidden lg:fixed lg:inset-y-0 lg:flex lg:w-64 lg:flex-col border-r border-primary-100 bg-white">
      <div class="flex h-16 items-center gap-2.5 px-5 border-b border-primary-100">
        <AppLogo :size="34" />
        <div class="leading-tight">
          <p class="font-display text-sm font-bold text-primary-800">Jawa Dwipa</p>
          <p class="text-[10px] tracking-widest text-gold-600 font-semibold">COOPERATIVE</p>
        </div>
      </div>
      <nav class="flex-1 overflow-y-auto px-3 py-5 space-y-6">
        <div class="space-y-1">
          <RouterLink
            v-for="link in memberLinks"
            :key="link.to"
            :to="link.to"
            class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition"
            :class="isActive(link.to) ? 'bg-primary-700 text-white shadow-sm' : 'text-primary-700 hover:bg-primary-50'"
          >
            <span class="text-base">{{ link.icon }}</span>{{ link.label }}
          </RouterLink>
        </div>

        <div v-if="authStore.isAdmin" class="space-y-1">
          <p class="px-3 text-[11px] font-semibold uppercase tracking-wider text-secondary-500">Panel Admin</p>
          <RouterLink
            v-for="link in adminLinks"
            :key="link.to"
            :to="link.to"
            class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition"
            :class="isActive(link.to) ? 'bg-secondary-700 text-white shadow-sm' : 'text-secondary-700 hover:bg-secondary-50'"
          >
            <span class="text-base">{{ link.icon }}</span>{{ link.label }}
          </RouterLink>
        </div>
      </nav>
      <div class="border-t border-primary-100 p-4">
        <button class="btn-secondary w-full" :disabled="logoutMutation.isPending.value" @click="logoutMutation.mutate()">
          Keluar
        </button>
      </div>
    </aside>

    <!-- Header mobile -->
    <header class="lg:hidden sticky top-0 z-20 flex h-16 items-center justify-between border-b border-primary-100 bg-white px-4">
      <div class="flex items-center gap-2">
        <AppLogo :size="30" />
        <span class="font-display text-sm font-bold text-primary-800">Jawa Dwipa</span>
      </div>
      <button class="rounded-lg p-2 text-primary-700 hover:bg-primary-50" @click="mobileNavOpen = !mobileNavOpen" aria-label="Menu">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
        </svg>
      </button>
    </header>
    <div v-if="mobileNavOpen" class="lg:hidden border-b border-primary-100 bg-white px-3 py-3 space-y-1">
      <RouterLink
        v-for="link in [...memberLinks, ...(authStore.isAdmin ? adminLinks : [])]"
        :key="link.to"
        :to="link.to"
        class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium"
        :class="isActive(link.to) ? 'bg-primary-700 text-white' : 'text-primary-700 hover:bg-primary-50'"
        @click="mobileNavOpen = false"
      >
        <span>{{ link.icon }}</span>{{ link.label }}
      </RouterLink>
      <button class="btn-secondary w-full mt-2" @click="logoutMutation.mutate()">Keluar</button>
    </div>

    <div class="lg:pl-64">
      <header class="hidden lg:flex sticky top-0 z-10 h-16 items-center justify-between border-b border-primary-100 bg-white/80 backdrop-blur px-8">
        <h1 class="font-display text-lg font-semibold text-primary-800">{{ $route.meta.title }}</h1>
        <div v-if="authStore.user" class="flex items-center gap-3">
          <div class="text-right leading-tight">
            <p class="text-sm font-medium text-primary-800">{{ authStore.user.nama_lengkap }}</p>
            <p class="text-xs text-gold-700 font-semibold">{{ roleLabel(authStore.user.role) }}</p>
          </div>
          <div class="flex h-9 w-9 items-center justify-center rounded-full bg-primary-100 text-primary-700 font-semibold">
            {{ authStore.user.nama_lengkap.charAt(0).toUpperCase() }}
          </div>
        </div>
      </header>

      <main class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <slot />
      </main>
    </div>
  </div>
</template>
