<script setup lang="ts">
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import DashboardShell from '@/components/DashboardShell.vue';
import Skeleton from '@/components/Skeleton.vue';
import EmptyState from '@/components/EmptyState.vue';
import { useDeposit, useSavingsAccounts } from '@/composables/useSavings';
import { apiErrorMessage } from '@/lib/api';
import { formatRupiah } from '@/lib/utils';

const accountsQuery = useSavingsAccounts();
const depositMutation = useDeposit();

const presets = [100_000, 250_000, 500_000, 1_000_000];
const amount = ref(100_000);

type Method = { key: string; label: string; hint: string };
const methods: Method[] = [
  { key: 'Transfer Bank (VA BCA)', label: 'Transfer Bank (VA BCA)', hint: 'Bayar via Virtual Account, verifikasi otomatis oleh admin.' },
  { key: 'QRIS', label: 'QRIS', hint: 'Pindai kode QR dari e-wallet atau m-banking mana pun.' },
  { key: 'Potong Gaji (Payroll)', label: 'Potong Gaji (Payroll)', hint: 'Dipotong langsung dari gaji bulan berjalan.' },
];
const selectedMethod = ref(methods[0].key);

// Top-up masuk ke rekening Simpanan Sukarela — rekening yang memang dipakai
// untuk setoran bebas (bukan Pokok/Wajib yang jumlah & waktunya tetap).
const targetAccount = computed(() => {
  const accounts = accountsQuery.data.value ?? [];
  return accounts.find((a) => a.product_name === 'Simpanan Sukarela') ?? accounts[0] ?? null;
});

const submitting = ref(false);

async function handleSubmit() {
  if (!targetAccount.value || amount.value <= 0) return;
  submitting.value = true;
  try {
    await depositMutation.mutateAsync({
      account_id: targetAccount.value.id,
      amount: amount.value,
      payment_method: selectedMethod.value,
    });
    toast.success('Permohonan top-up terkirim, menunggu verifikasi admin.');
    amount.value = 100_000;
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Gagal mengajukan top-up.'));
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <DashboardShell>
    <h2 class="font-display text-xl font-semibold text-primary-800 mb-1">Top-up Saldo</h2>
    <p class="text-sm text-primary-500 mb-6">Isi saldo Simpanan Sukarela Anda kapan saja.</p>

    <Skeleton v-if="accountsQuery.isPending.value" :rows="2" />
    <EmptyState
      v-else-if="!targetAccount"
      icon="🏦"
      title="Belum ada rekening simpanan"
      description="Buka rekening simpanan terlebih dahulu di halaman Simpanan sebelum melakukan top-up."
    />

    <div v-else class="card max-w-xl space-y-6">
      <div>
        <label class="label">Jumlah Top-up</label>
        <input v-model.number="amount" type="number" min="1" step="1000" class="input font-display text-lg font-bold" />
        <div class="mt-2.5 grid grid-cols-4 gap-2">
          <button
            v-for="p in presets"
            :key="p"
            type="button"
            class="rounded-lg border px-2 py-2 text-xs font-medium transition"
            :class="amount === p ? 'border-primary-700 bg-primary-50 text-primary-800' : 'border-primary-200 text-primary-600 hover:bg-primary-50'"
            @click="amount = p"
          >
            Rp {{ p / 1000 }}rb
          </button>
        </div>
      </div>

      <div>
        <p class="label mb-2">Metode Pembayaran</p>
        <div class="space-y-2">
          <label
            v-for="m in methods"
            :key="m.key"
            class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition"
            :class="selectedMethod === m.key ? 'border-primary-700 bg-primary-50' : 'border-primary-200 hover:bg-cream-50'"
          >
            <input v-model="selectedMethod" type="radio" :value="m.key" class="mt-1" />
            <div>
              <p class="font-medium text-primary-800">{{ m.label }}</p>
              <p class="text-xs text-primary-500">{{ m.hint }}</p>
            </div>
          </label>
        </div>
      </div>

      <div class="rounded-lg bg-cream-100 px-4 py-3 text-sm text-primary-600">
        Menuju <span class="font-semibold text-primary-800">{{ targetAccount.product_name }}</span>
        &middot; Saldo saat ini {{ formatRupiah(targetAccount.balance) }}
      </div>

      <button type="button" class="btn-gold w-full" :disabled="submitting || amount <= 0" @click="handleSubmit">
        {{ submitting ? 'Memproses...' : 'Lanjutkan Pembayaran' }}
      </button>
    </div>
  </DashboardShell>
</template>
