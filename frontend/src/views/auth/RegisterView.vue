<script setup lang="ts">
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { toast } from 'vue-sonner';
import AuthLayout from './AuthLayout.vue';
import { useRegister } from '@/composables/useAuth';
import { apiErrorMessage } from '@/lib/api';

const router = useRouter();
const registerMutation = useRegister();

const namaLengkap = ref('');
const email = ref('');
const password = ref('');
const confirmPassword = ref('');
const error = ref<string | null>(null);

const passwordMismatch = computed(() => !!confirmPassword.value && password.value !== confirmPassword.value);
const canSubmit = computed(
  () =>
    namaLengkap.value.length >= 3 &&
    !!email.value &&
    password.value.length >= 8 &&
    !passwordMismatch.value &&
    !registerMutation.isPending.value,
);

// PENTING: POST /register TIDAK mengembalikan token — backend mewajibkan
// verifikasi OTP dulu (POST /verify-email) sebelum token diterbitkan.
// Jangan simpan cookie di sini; arahkan ke halaman OTP.
async function handleSubmit() {
  error.value = null;
  if (passwordMismatch.value) {
    error.value = 'Konfirmasi kata sandi tidak cocok.';
    return;
  }
  try {
    await registerMutation.mutateAsync({
      nama_lengkap: namaLengkap.value,
      email: email.value,
      password: password.value,
    });
    toast.success('Registrasi berhasil, OTP telah dikirim ke email Anda.');
    router.push({ name: 'verify-otp', query: { email: email.value } });
  } catch (err) {
    error.value = apiErrorMessage(err, 'Data yang dimasukkan tidak valid.');
  }
}
</script>

<template>
  <AuthLayout>
    <h2 class="font-display text-xl font-semibold text-primary-800 mb-1">Buat Akun Anggota Baru</h2>
    <p class="text-sm text-primary-500 mb-6">Gabung Koperasi Syariah Jawa Dwipa hari ini.</p>

    <form class="space-y-5" novalidate @submit.prevent="handleSubmit">
      <div
        v-if="error"
        role="alert"
        class="flex items-start gap-2.5 rounded-lg bg-secondary-50 border border-secondary-200 px-4 py-3 text-sm text-secondary-800"
      >
        <span>⚠️</span><span>{{ error }}</span>
      </div>

      <div>
        <label class="label" for="nama">Nama Lengkap</label>
        <input id="nama" v-model="namaLengkap" type="text" autocomplete="name" required placeholder="Sesuai KTP" class="input" />
        <p class="mt-1 text-xs text-primary-400">Minimal 3 karakter</p>
      </div>

      <div>
        <label class="label" for="email">Alamat Email</label>
        <input id="email" v-model="email" type="email" autocomplete="email" required placeholder="anda@email.com" class="input" />
      </div>

      <div>
        <label class="label" for="password">Kata Sandi</label>
        <input id="password" v-model="password" type="password" autocomplete="new-password" required placeholder="Minimal 8 karakter" class="input" />
      </div>

      <div>
        <label class="label" for="confirm">Konfirmasi Kata Sandi</label>
        <input
          id="confirm"
          v-model="confirmPassword"
          type="password"
          autocomplete="new-password"
          required
          placeholder="Ulangi kata sandi"
          class="input"
          :class="passwordMismatch && 'border-secondary-400 focus:border-secondary-400 focus:ring-secondary-400/20'"
        />
        <p v-if="passwordMismatch" class="mt-1 text-xs text-secondary-600">Kata sandi tidak cocok</p>
      </div>

      <button type="submit" class="btn-primary w-full" :disabled="!canSubmit">
        <span v-if="registerMutation.isPending.value" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white" />
        {{ registerMutation.isPending.value ? 'Mendaftarkan...' : 'Daftar Sekarang' }}
      </button>
    </form>

    <p class="mt-6 text-center text-xs text-primary-500">
      Sudah punya akun?
      <RouterLink :to="{ name: 'login' }" class="font-semibold text-primary-700 hover:underline">Masuk di sini</RouterLink>
    </p>
  </AuthLayout>
</template>
