<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { toast } from 'vue-sonner';
import AuthLayout from './AuthLayout.vue';
import { useVerifyEmail, useResendOtp } from '@/composables/useAuth';
import { apiErrorMessage } from '@/lib/api';

const route = useRoute();
const router = useRouter();
const verifyMutation = useVerifyEmail();
const resendMutation = useResendOtp();

const email = ref((route.query.email as string) ?? '');
const otp = ref('');
const error = ref<string | null>(null);
const cooldown = ref(0);
let timer: ReturnType<typeof setInterval> | undefined;

function startCooldown() {
  cooldown.value = 60;
  timer = setInterval(() => {
    cooldown.value -= 1;
    if (cooldown.value <= 0 && timer) clearInterval(timer);
  }, 1000);
}

onMounted(() => {
  if (!email.value) {
    toast.info('Masukkan email yang Anda daftarkan untuk verifikasi.');
  }
});
onUnmounted(() => {
  if (timer) clearInterval(timer);
});

const canSubmit = computed(() => !!email.value && otp.value.length === 6 && !verifyMutation.isPending.value);

async function handleSubmit() {
  error.value = null;
  try {
    await verifyMutation.mutateAsync({ email: email.value, otp: otp.value });
    toast.success('Email terverifikasi! Selamat datang di Jawa Dwipa Cooperative.');
    router.push({ name: 'dashboard' });
  } catch (err) {
    error.value = apiErrorMessage(err, 'Kode OTP salah atau sudah kedaluwarsa.');
  }
}

async function handleResend() {
  if (!email.value || cooldown.value > 0) return;
  try {
    await resendMutation.mutateAsync(email.value);
    toast.success('Jika email terdaftar dan belum diverifikasi, OTP baru telah dikirim.');
    startCooldown();
  } catch (err) {
    toast.error(apiErrorMessage(err));
  }
}
</script>

<template>
  <AuthLayout>
    <h2 class="font-display text-xl font-semibold text-primary-800 mb-1">Verifikasi Email</h2>
    <p class="text-sm text-primary-500 mb-6">
      Masukkan kode OTP 6 digit yang telah dikirim ke email Anda untuk mengaktifkan akun.
    </p>

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
        <input id="email" v-model="email" type="email" required placeholder="anda@email.com" class="input" />
      </div>

      <div>
        <label class="label" for="otp">Kode OTP</label>
        <input
          id="otp"
          v-model="otp"
          type="text"
          inputmode="numeric"
          maxlength="6"
          required
          placeholder="123456"
          class="input text-center text-2xl tracking-[0.5em] font-semibold"
          @input="otp = otp.replace(/\D/g, '').slice(0, 6)"
        />
        <p class="mt-1 text-xs text-primary-400">Berlaku sementara — cek folder spam bila belum masuk.</p>
      </div>

      <button type="submit" class="btn-primary w-full" :disabled="!canSubmit">
        <span v-if="verifyMutation.isPending.value" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white" />
        {{ verifyMutation.isPending.value ? 'Memverifikasi...' : 'Verifikasi & Masuk' }}
      </button>
    </form>

    <p class="mt-6 text-center text-xs text-primary-500">
      Tidak menerima kode?
      <button
        type="button"
        class="font-semibold text-primary-700 hover:underline disabled:cursor-not-allowed disabled:text-primary-300 disabled:no-underline"
        :disabled="cooldown > 0 || resendMutation.isPending.value"
        @click="handleResend"
      >
        {{ cooldown > 0 ? `Kirim ulang (${cooldown}s)` : 'Kirim ulang OTP' }}
      </button>
    </p>
  </AuthLayout>
</template>
