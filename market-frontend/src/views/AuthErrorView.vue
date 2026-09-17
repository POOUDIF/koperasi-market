<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import MarketShell from '@/components/MarketShell.vue';
import { ssoLoginUrl } from '@/lib/sso';

const route = useRoute();
const MESSAGES: Record<string, string> = {
  invalid_state: 'Sesi masuk kedaluwarsa atau dibuka dari tab lain.',
  access_denied: 'Proses masuk dibatalkan.',
  token_invalid: 'Verifikasi login gagal.',
  idp_unavailable: 'Layanan Akun JDC sedang tidak dapat dihubungi.',
  session_unavailable: 'Layanan sesi sedang bermasalah.',
};
const message = computed(() => MESSAGES[String(route.query.code ?? '')] ?? 'Terjadi kesalahan saat masuk.');
const retry = ssoLoginUrl(import.meta.env.BASE_URL);
</script>

<template>
  <MarketShell>
    <div class="card mx-auto max-w-md text-center">
      <h1 class="text-xl font-bold">Tidak dapat masuk</h1>
      <p class="mt-2 text-sm text-primary-600">{{ message }} Silakan coba lagi.</p>
      <a :href="retry" class="btn-primary mt-6">Coba Masuk Lagi</a>
    </div>
  </MarketShell>
</template>
