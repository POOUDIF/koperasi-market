<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { toast } from 'vue-sonner';
import AuthLayout from './AuthLayout.vue';
import { useLogin } from '@/composables/useAuth';
import { apiErrorMessage } from '@/lib/api';

const router = useRouter();
const route = useRoute();
const loginMutation = useLogin();

const email = ref('');
const password = ref('');
const error = ref<string | null>(null);

onMounted(() => {
  if (route.query.reason === 'account_disabled') {
    error.value = 'Akun Anda telah dinonaktifkan. Silakan hubungi admin koperasi.';
  }
});

const canSubmit = computed(() => !!email.value && !!password.value && !loginMutation.isPending.value);

async function handleSubmit() {
  error.value = null;
  try {
    await loginMutation.mutateAsync({ email: email.value, password: password.value });
    toast.success('Login berhasil');
    router.push({ name: 'dashboard' });
  } catch (err) {
    // Pesan 401 sengaja digabung di backend (anti-enumeration email/password).
    error.value = apiErrorMessage(err, 'Email atau password tidak valid.');
  }
}
</script>

<template>
  <AuthLayout>
    <h2 class="font-display text-xl font-semibold text-primary-800 mb-1">Masuk ke Akun Anda</h2>
    <p class="text-sm text-primary-500 mb-6">Kelola simpanan, pembiayaan, dan emas digital Anda.</p>

    <form class="space-y-5" novalidate @submit.prevent="handleSubmit">
      <div
        v-if="error"
        role="alert"
        class="flex items-start gap-2.5 rounded-lg bg-secondary-50 border border-secondary-200 px-4 py-3 text-sm text-secondary-800"
      >
        <span>⚠️</span><span>{{ error }}</span>
      </div>

      <div>
        <label class="label" for="email">Alamat Email</label>
        <input id="email" v-model="email" type="email" autocomplete="email" required placeholder="anda@email.com" class="input" />
      </div>

      <div>
        <label class="label" for="password">Kata Sandi</label>
        <input id="password" v-model="password" type="password" autocomplete="current-password" required placeholder="Masukkan kata sandi" class="input" />
      </div>

      <button type="submit" class="btn-primary w-full" :disabled="!canSubmit">
        <span v-if="loginMutation.isPending.value" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white" />
        {{ loginMutation.isPending.value ? 'Memproses...' : 'Masuk' }}
      </button>
    </form>

    <p class="mt-6 text-center text-xs text-primary-500">
      Belum punya akun?
      <RouterLink :to="{ name: 'register' }" class="font-semibold text-primary-700 hover:underline">Daftar di sini</RouterLink>
    </p>
  </AuthLayout>
</template>
