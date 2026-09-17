<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import AppLogo from '@jdc/ui/vue/AppLogo.vue';
import AppSwitcher from '@jdc/ui/vue/AppSwitcher.vue';
import { useSessionStore } from '@/stores/session';
import { ssoLoginUrl, resetSilentLogin } from '@/lib/sso';
import api from '@/lib/api';

const session = useSessionStore();
const router = useRouter();
const q = ref('');
const menuOpen = ref(false);
const loginUrl = () => ssoLoginUrl(window.location.pathname + window.location.search);

function search() {
  router.push({ name: 'home', query: q.value.trim() ? { q: q.value.trim() } : {} });
}

async function logout() {
  resetSilentLogin();
  try {
    const { data } = await api.post<{ redirect_url: string }>('/sso/logout');
    window.location.assign(data.redirect_url);
  } catch {
    window.location.assign('/');
  }
}
</script>

<template>
  <div class="min-h-screen bg-cream-100">
    <header class="sticky top-0 z-30 border-b border-primary-100 bg-white/95 backdrop-blur">
      <div class="mx-auto flex h-16 max-w-6xl items-center gap-3 px-4">
        <RouterLink to="/" class="flex shrink-0 items-center gap-2">
          <AppLogo :size="34" />
          <span class="hidden leading-tight sm:block">
            <span class="block font-display text-sm font-bold text-primary-800">Jawa Dwipa</span>
            <span class="block text-[10px] font-semibold tracking-widest text-gold-600">MARKETPLACE</span>
          </span>
        </RouterLink>

        <form class="flex-1" role="search" @submit.prevent="search">
          <label for="market-search" class="sr-only">Cari produk</label>
          <input id="market-search" v-model="q" type="search" class="input !py-2" placeholder="Cari produk anggota koperasi..." />
        </form>

        <AppSwitcher current="market" />

        <template v-if="session.isLoggedIn">
          <RouterLink to="/cart" class="relative rounded-lg p-2 text-primary-700 hover:bg-primary-50" aria-label="Keranjang">
            <span class="text-xl">🛒</span>
            <span v-if="session.me!.cart_count" class="absolute right-0.5 top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-gold-500 px-1 text-[10px] font-bold text-primary-900">
              {{ session.me!.cart_count > 99 ? '99+' : session.me!.cart_count }}
            </span>
          </RouterLink>
          <div class="relative">
            <button class="flex h-9 w-9 items-center justify-center rounded-full bg-primary-100 font-semibold text-primary-700" :aria-expanded="menuOpen" aria-label="Menu akun" @click="menuOpen = !menuOpen">
              {{ session.me!.name.charAt(0).toUpperCase() }}
            </button>
            <div v-if="menuOpen" class="absolute right-0 mt-2 w-56 rounded-xl border border-primary-100 bg-white p-2 shadow-card-hover" @click="menuOpen = false">
              <p class="truncate px-3 py-2 text-xs text-primary-500">{{ session.me!.email }}</p>
              <RouterLink to="/orders" class="block rounded-lg px-3 py-2 text-sm hover:bg-primary-50">Pesanan Saya</RouterLink>
              <RouterLink to="/seller" class="block rounded-lg px-3 py-2 text-sm hover:bg-primary-50">{{ session.me!.store ? 'Toko Saya' : 'Buka Toko' }}</RouterLink>
              <RouterLink v-if="session.isAdmin" to="/admin" class="block rounded-lg px-3 py-2 text-sm text-secondary-700 hover:bg-secondary-50">Admin Marketplace</RouterLink>
              <a href="/account" class="block rounded-lg px-3 py-2 text-sm hover:bg-primary-50">Akun JDC</a>
              <button class="block w-full rounded-lg px-3 py-2 text-left text-sm text-secondary-700 hover:bg-secondary-50" @click="logout">Keluar dari Semua Layanan</button>
            </div>
          </div>
        </template>
        <a v-else-if="session.loaded" :href="loginUrl()" class="btn-primary !py-2">Masuk</a>
      </div>
    </header>

    <div v-if="session.isLoggedIn && session.me!.membership.available && !session.isMember" class="border-b border-gold-200 bg-gold-50">
      <p class="mx-auto max-w-6xl px-4 py-2 text-sm text-gold-800">
        Pembayaran memakai saldo simpanan koperasi (Koperasi Pay) dan harga anggota.
        <a href="/koperasi/dashboard/membership" class="font-semibold underline">Aktifkan keanggotaan koperasi</a> untuk mulai berbelanja.
      </p>
    </div>

    <main class="mx-auto max-w-6xl px-4 py-6 sm:py-8">
      <slot />
    </main>

    <footer class="border-t border-primary-100 bg-white">
      <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-6 text-xs text-primary-500">
        <span>&copy; {{ new Date().getFullYear() }} Koperasi Digital Syariah Jawa Dwipa</span>
        <span class="flex gap-4">
          <a href="/" class="hover:underline">Beranda JDC</a>
          <a href="/koperasi/dashboard" class="hover:underline">Koperasi Digital</a>
        </span>
      </div>
    </footer>
  </div>
</template>
