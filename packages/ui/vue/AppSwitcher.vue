<script setup lang="ts">
/**
 * Pengalih layanan JDC — satu akun, beberapa layanan (§3.3). Tautan biasa
 * (navigasi penuh), bukan router: setiap layanan adalah aplikasi terpisah
 * yang mengurus sesi SSO-nya sendiri.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';

type ServiceKey = 'home' | 'koperasi' | 'market' | 'account';

const props = defineProps<{ current: ServiceKey }>();

const services: { key: ServiceKey; label: string; desc: string; href: string; icon: string }[] = [
  { key: 'home', label: 'Beranda JDC', desc: 'Profil koperasi', href: '/', icon: '🏛️' },
  { key: 'koperasi', label: 'Koperasi Digital', desc: 'Simpanan, pembiayaan, emas', href: '/koperasi/dashboard', icon: '🏦' },
  { key: 'market', label: 'Marketplace', desc: 'Belanja produk anggota', href: '/market/', icon: '🛍️' },
  { key: 'account', label: 'Akun JDC', desc: 'Profil, kata sandi, perangkat', href: '/account', icon: '👤' },
];

const open = ref(false);
const root = ref<HTMLElement | null>(null);

function onDocClick(e: MouseEvent) {
  if (root.value && !root.value.contains(e.target as Node)) open.value = false;
}
function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') open.value = false;
}
onMounted(() => {
  document.addEventListener('click', onDocClick);
  document.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => {
  document.removeEventListener('click', onDocClick);
  document.removeEventListener('keydown', onKey);
});
</script>

<template>
  <div ref="root" class="relative">
    <button
      type="button"
      class="flex items-center gap-1.5 rounded-lg px-2.5 py-2 text-sm font-medium text-primary-700 hover:bg-primary-50"
      :aria-expanded="open"
      aria-haspopup="menu"
      aria-label="Layanan JDC"
      @click="open = !open"
    >
      <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
        <circle cx="5" cy="5" r="2" /><circle cx="12" cy="5" r="2" /><circle cx="19" cy="5" r="2" />
        <circle cx="5" cy="12" r="2" /><circle cx="12" cy="12" r="2" /><circle cx="19" cy="12" r="2" />
        <circle cx="5" cy="19" r="2" /><circle cx="12" cy="19" r="2" /><circle cx="19" cy="19" r="2" />
      </svg>
      <span class="hidden sm:inline">Layanan</span>
    </button>
    <div
      v-if="open"
      role="menu"
      class="absolute right-0 z-50 mt-2 w-72 rounded-xl border border-primary-100 bg-white p-2 shadow-card-hover"
    >
      <a
        v-for="s in services"
        :key="s.key"
        :href="s.href"
        role="menuitem"
        class="flex items-start gap-3 rounded-lg px-3 py-2.5 hover:bg-primary-50"
        :class="s.key === props.current ? 'bg-primary-50' : ''"
        :aria-current="s.key === props.current ? 'page' : undefined"
      >
        <span class="text-xl leading-none" aria-hidden="true">{{ s.icon }}</span>
        <span>
          <span class="block text-sm font-semibold text-primary-800">{{ s.label }}</span>
          <span class="block text-xs text-primary-500">{{ s.desc }}</span>
        </span>
      </a>
    </div>
  </div>
</template>
