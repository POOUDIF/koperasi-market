<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import AuthLayout from './AuthLayout.vue';
import { ssoLoginUrl } from '@/lib/sso';

const route = useRoute();

// Kode berasal dari backend (/sso/callback) — dipetakan ke teks tetap,
// tidak pernah menampilkan isi query string mentah.
const MESSAGES: Record<string, string> = {
  invalid_state: 'Sesi masuk kedaluwarsa atau dibuka dari tab lain. Silakan coba masuk lagi.',
  access_denied: 'Proses masuk dibatalkan.',
  token_invalid: 'Verifikasi login gagal. Silakan coba masuk lagi.',
  idp_unavailable: 'Layanan Akun JDC sedang tidak dapat dihubungi. Coba beberapa saat lagi.',
  session_unavailable: 'Layanan sesi sedang bermasalah. Coba beberapa saat lagi.',
  account_link_conflict:
    'Email Anda sudah tercatat di data koperasi lama dan perlu ditautkan oleh pengurus. Hubungi admin koperasi.',
  account_suspended: 'Akun koperasi Anda dinonaktifkan. Hubungi admin koperasi.',
  email_not_verified: 'Email akun belum diverifikasi.',
};

const message = computed(
  () => MESSAGES[String(route.query.code ?? '')] ?? 'Terjadi kesalahan saat masuk. Silakan coba lagi.',
);
const retryUrl = ssoLoginUrl(`${import.meta.env.BASE_URL}dashboard`);
</script>

<template>
  <AuthLayout>
    <h2 class="font-display text-xl font-semibold text-primary-800 mb-2">Tidak dapat masuk</h2>
    <p class="text-sm text-primary-600 mb-6">{{ message }}</p>
    <div class="flex gap-3">
      <a href="/" class="btn-secondary flex-1">Beranda JDC</a>
      <a :href="retryUrl" class="btn-primary flex-1">Coba Masuk Lagi</a>
    </div>
  </AuthLayout>
</template>
