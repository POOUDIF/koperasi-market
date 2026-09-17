<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { toast } from 'vue-sonner';
import AppLogo from '@jdc/ui/vue/AppLogo.vue';
import Skeleton from '@/components/Skeleton.vue';
import { useCancelPayment, useConfirmPayment, usePaymentIntent } from '@/composables/usePayments';
import { apiErrorCode, apiErrorMessage } from '@/lib/api';
import { formatDate, formatRupiah } from '@/lib/utils';

/**
 * Halaman konfirmasi Koperasi Pay (§4.3). Dibuka dari Marketplace; anggota
 * memilih rekening & memasukkan PIN di domain koperasi — marketplace tidak
 * pernah bisa mendebet saldo tanpa langkah ini.
 */
const props = defineProps<{ id: string }>();

const intentQuery = usePaymentIntent(() => props.id);
const confirmMutation = useConfirmPayment(() => props.id);
const cancelMutation = useCancelPayment(() => props.id);

const intent = computed(() => intentQuery.data.value);
const accountId = ref<number | null>(null);
const pin = ref('');
const error = ref<string | null>(null);

watch(intent, (v) => {
  if (v && accountId.value === null && v.accounts.length > 0) {
    const enough = v.accounts.find((a) => a.balance >= v.amount);
    accountId.value = (enough ?? v.accounts[0]).id;
  }
});

const selected = computed(() => intent.value?.accounts.find((a) => a.id === accountId.value) ?? null);
const insufficient = computed(() => !!selected.value && !!intent.value && selected.value.balance < intent.value.amount);
const canPay = computed(
  () => intent.value?.status === 'requires_confirmation' && intent.value.has_pin && !!selected.value
    && !insufficient.value && /^\d{6}$/.test(pin.value) && !confirmMutation.isPending.value,
);

async function pay() {
  error.value = null;
  try {
    const res = await confirmMutation.mutateAsync({ account_id: accountId.value!, pin: pin.value });
    toast.success('Pembayaran berhasil.');
    window.location.assign(res.redirect_url);
  } catch (err) {
    pin.value = '';
    const code = apiErrorCode(err);
    error.value = code === 'PIN_LOCKED'
      ? 'PIN terkunci karena terlalu banyak percobaan. Coba lagi dalam 30 menit.'
      : apiErrorMessage(err, 'Pembayaran gagal.');
    intentQuery.refetch();
  }
}

async function cancel() {
  try {
    const res = await cancelMutation.mutateAsync();
    window.location.assign(res.redirect_url);
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Pembatalan gagal.'));
  }
}
</script>

<template>
  <main class="auth-bg flex min-h-screen items-center justify-center p-4">
    <div class="w-full max-w-lg">
      <div class="mb-6 flex items-center justify-center gap-3 text-white">
        <AppLogo :size="44" />
        <div>
          <p class="font-display text-lg font-bold leading-tight">Koperasi Pay</p>
          <p class="text-xs text-primary-100/80">Pembayaran dari saldo simpanan Anda</p>
        </div>
      </div>

      <div class="rounded-2xl bg-white p-6 shadow-2xl sm:p-8">
        <Skeleton v-if="intentQuery.isPending.value" :rows="5" />

        <div v-else-if="intentQuery.isError.value" class="text-center">
          <h1 class="text-xl font-semibold">Tagihan tidak ditemukan</h1>
          <p class="mt-2 text-sm text-primary-500">Tautan pembayaran tidak valid atau bukan milik akun Anda.</p>
          <a href="/market/orders" class="btn-primary mt-6">Kembali ke Marketplace</a>
        </div>

        <template v-else-if="intent">
          <p class="text-xs font-semibold uppercase tracking-wider text-primary-500">{{ intent.merchant_name }}</p>
          <h1 class="mt-1 text-lg font-semibold">{{ intent.description }}</h1>
          <p class="mt-4 font-display text-3xl font-bold text-primary-800">{{ formatRupiah(intent.amount) }}</p>
          <p class="mt-1 text-xs text-primary-400">Ref. {{ intent.merchant_ref }} · berlaku sampai {{ formatDate(intent.expires_at) }}</p>

          <div v-if="intent.status !== 'requires_confirmation'" class="mt-6 rounded-lg border border-primary-200 bg-primary-50 px-4 py-3 text-sm text-primary-800">
            <template v-if="intent.status === 'held'">Tagihan ini sudah dibayar.</template>
            <template v-else-if="intent.status === 'expired'">Tagihan kedaluwarsa. Buat pembayaran baru dari halaman pesanan.</template>
            <template v-else-if="intent.status === 'cancelled'">Tagihan dibatalkan.</template>
            <template v-else>Status tagihan: {{ intent.status }}.</template>
            <a :href="intent.return_url" class="mt-3 block font-semibold underline">Kembali ke Marketplace</a>
          </div>

          <template v-else>
            <div v-if="!intent.has_pin" class="mt-6 rounded-lg border border-gold-200 bg-gold-50 px-4 py-3 text-sm text-gold-800">
              Buat PIN transaksi terlebih dahulu untuk memakai Koperasi Pay.
              <RouterLink :to="{ name: 'security' }" class="mt-2 block font-semibold underline">Buat PIN sekarang</RouterLink>
            </div>

            <div v-else-if="intent.accounts.length === 0" class="mt-6 rounded-lg border border-gold-200 bg-gold-50 px-4 py-3 text-sm text-gold-800">
              Anda belum punya rekening yang bisa dipakai membayar (mis. Simpanan Sukarela).
              <RouterLink to="/dashboard/savings" class="mt-2 block font-semibold underline">Buka rekening</RouterLink>
            </div>

            <form v-else class="mt-6 space-y-5" @submit.prevent="pay">
              <div v-if="error" role="alert" class="rounded-lg border border-secondary-200 bg-secondary-50 px-4 py-3 text-sm text-secondary-800">{{ error }}</div>

              <fieldset>
                <legend class="label">Bayar dari rekening</legend>
                <div class="space-y-2">
                  <label
                    v-for="a in intent.accounts"
                    :key="a.id"
                    class="flex cursor-pointer items-center justify-between rounded-lg border px-4 py-3"
                    :class="accountId === a.id ? 'border-primary-500 bg-primary-50' : 'border-primary-100'"
                  >
                    <span class="flex items-center gap-3">
                      <input v-model="accountId" type="radio" :value="a.id" class="h-4 w-4 text-primary-700" />
                      <span class="text-sm font-medium">{{ a.product_name }}</span>
                    </span>
                    <span class="text-sm" :class="a.balance < intent.amount ? 'text-secondary-700' : 'text-primary-700'">{{ formatRupiah(a.balance) }}</span>
                  </label>
                </div>
                <p v-if="insufficient" class="mt-2 text-xs text-secondary-700">Saldo rekening ini tidak mencukupi.</p>
              </fieldset>

              <div>
                <label class="label" for="pin">PIN Transaksi</label>
                <input id="pin" v-model="pin" type="password" inputmode="numeric" maxlength="6" autocomplete="off" class="input text-center text-xl tracking-[0.5em]" placeholder="••••••" />
              </div>

              <p class="text-xs text-primary-500">
                Dana ditahan koperasi dan baru diteruskan ke penjual setelah pesanan Anda terima. Bila pesanan dibatalkan, dana dikembalikan ke rekening ini.
              </p>

              <button type="submit" class="btn-primary w-full" :disabled="!canPay">
                {{ confirmMutation.isPending.value ? 'Memproses...' : `Bayar ${formatRupiah(intent.amount)}` }}
              </button>
            </form>

            <button class="mt-3 w-full text-center text-sm font-semibold text-secondary-700 hover:underline" :disabled="cancelMutation.isPending.value" @click="cancel">
              Batalkan pembayaran
            </button>
          </template>
        </template>
      </div>
    </div>
  </main>
</template>
