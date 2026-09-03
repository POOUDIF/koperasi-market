<script setup lang="ts">
import { ref, reactive, computed } from 'vue';
import { toast } from 'vue-sonner';
import DashboardShell from '@/components/DashboardShell.vue';
import Skeleton from '@/components/Skeleton.vue';
import EmptyState from '@/components/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import FormModal from '@/components/FormModal.vue';
import { useBuyGold, useGoldHolding, useGoldPrice, useSellGold } from '@/composables/useGold';
import { useSavingsAccounts } from '@/composables/useSavings';
import { apiErrorMessage } from '@/lib/api';
import { formatDate, formatGram, formatRupiah } from '@/lib/utils';

const priceQuery = useGoldPrice();
const holdingQuery = useGoldHolding();
const accountsQuery = useSavingsAccounts();
const buyMutation = useBuyGold();
const sellMutation = useSellGold();

const tradeModal = ref<'buy' | 'sell' | null>(null);
const form = reactive({ gram_amount: 0, savings_account_id: 0 });

function openTrade(type: 'buy' | 'sell') {
  form.gram_amount = 0;
  form.savings_account_id = accountsQuery.data.value?.[0]?.id ?? 0;
  tradeModal.value = type;
}

const activePricePerGram = computed(() => {
  if (!priceQuery.data.value) return 0;
  return tradeModal.value === 'sell' ? priceQuery.data.value.sell_price_per_gram : priceQuery.data.value.buy_price_per_gram;
});
const estimatedTotal = computed(() => form.gram_amount * activePricePerGram.value);

async function handleTrade() {
  try {
    if (tradeModal.value === 'buy') {
      await buyMutation.mutateAsync({ gram_amount: form.gram_amount, savings_account_id: form.savings_account_id });
      toast.success('Transaksi beli emas dikirim, sedang diproses di blockchain.');
    } else if (tradeModal.value === 'sell') {
      await sellMutation.mutateAsync({ gram_amount: form.gram_amount, savings_account_id: form.savings_account_id });
      toast.success('Transaksi jual emas berhasil diajukan.');
    }
    tradeModal.value = null;
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Transaksi emas gagal.'));
  }
}

const transactions = computed(() => holdingQuery.data.value?.transactions ?? []);
</script>

<template>
  <DashboardShell>
    <div class="mb-6">
      <h2 class="font-display text-xl font-semibold text-primary-800">Emas Digital</h2>
      <p class="text-sm text-primary-500 mt-1">Investasi emas berbasis blockchain Polygon.</p>
    </div>

    <div v-if="priceQuery.isError.value" class="mb-6 flex items-start gap-2.5 rounded-lg bg-secondary-50 border border-secondary-200 px-4 py-3 text-sm text-secondary-800">
      <span>⚠️</span><span>Harga emas sedang tidak tersedia. Silakan coba beberapa saat lagi.</span>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
      <div class="card">
        <p class="text-xs font-medium text-primary-500 uppercase tracking-wide">Harga Beli</p>
        <Skeleton v-if="priceQuery.isPending.value" :rows="1" />
        <p v-else class="mt-1 font-display text-xl font-bold text-primary-800">{{ formatRupiah(priceQuery.data.value?.buy_price_per_gram ?? 0) }}<span class="text-xs font-sans font-normal text-primary-400">/gr</span></p>
      </div>
      <div class="card">
        <p class="text-xs font-medium text-primary-500 uppercase tracking-wide">Harga Jual</p>
        <Skeleton v-if="priceQuery.isPending.value" :rows="1" />
        <p v-else class="mt-1 font-display text-xl font-bold text-primary-800">{{ formatRupiah(priceQuery.data.value?.sell_price_per_gram ?? 0) }}<span class="text-xs font-sans font-normal text-primary-400">/gr</span></p>
      </div>
      <div class="card bg-gold-50 border-gold-200">
        <p class="text-xs font-medium text-gold-700 uppercase tracking-wide">Kepemilikan Anda</p>
        <Skeleton v-if="holdingQuery.isPending.value" :rows="1" />
        <p v-else class="mt-1 font-display text-xl font-bold text-gold-800">{{ formatGram(holdingQuery.data.value?.net_gram ?? 0) }}</p>
      </div>
    </div>

    <p v-if="priceQuery.data.value" class="text-xs text-primary-400 mb-6">
      Terakhir diperbarui: {{ formatDate(priceQuery.data.value.updated_at) }}
    </p>

    <div class="flex gap-3 mb-8">
      <button class="btn-gold" :disabled="priceQuery.isError.value" @click="openTrade('buy')">Beli Emas</button>
      <button class="btn-secondary" :disabled="priceQuery.isError.value" @click="openTrade('sell')">Jual Emas</button>
    </div>

    <h3 class="font-display text-lg font-semibold text-primary-800 mb-4">Riwayat Transaksi</h3>
    <Skeleton v-if="holdingQuery.isPending.value" :rows="3" />
    <EmptyState v-else-if="!transactions.length" icon="🥇" title="Belum ada transaksi emas" />

    <div v-else class="card !p-0 overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-cream-100 text-left text-xs uppercase tracking-wide text-primary-500">
          <tr>
            <th class="px-4 py-3">Tanggal</th>
            <th class="px-4 py-3">Tipe</th>
            <th class="px-4 py-3">Gram</th>
            <th class="px-4 py-3">Total</th>
            <th class="px-4 py-3">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-primary-50">
          <tr v-for="tx in transactions" :key="tx.id">
            <td class="px-4 py-3 text-primary-600">{{ formatDate(tx.created_at) }}</td>
            <td class="px-4 py-3 font-medium" :class="tx.type === 'buy' ? 'text-primary-700' : 'text-secondary-700'">
              {{ tx.type === 'buy' ? 'Beli' : 'Jual' }}
            </td>
            <td class="px-4 py-3 text-primary-600">{{ formatGram(tx.gram_amount) }}</td>
            <td class="px-4 py-3 font-medium text-primary-800">{{ formatRupiah(tx.total_rupiah) }}</td>
            <td class="px-4 py-3">
              <StatusBadge :status="tx.status" />
              <p v-if="tx.status === 'failed'" class="text-[11px] text-primary-400 mt-0.5">Saldo sudah dikembalikan otomatis</p>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <FormModal
      :open="tradeModal !== null"
      :title="tradeModal === 'buy' ? 'Beli Emas' : 'Jual Emas'"
      description="Transaksi diproses secara asinkron melalui blockchain Polygon."
      @close="tradeModal = null"
    >
      <form class="space-y-4" @submit.prevent="handleTrade">
        <div>
          <label class="label">Jumlah (gram)</label>
          <input v-model.number="form.gram_amount" type="number" min="0.0001" step="0.0001" required class="input" />
        </div>
        <div>
          <label class="label">Rekening {{ tradeModal === 'buy' ? 'Sumber Dana' : 'Tujuan Dana' }}</label>
          <select v-model.number="form.savings_account_id" class="input" required>
            <option v-for="acc in accountsQuery.data.value" :key="acc.id" :value="acc.id">
              {{ acc.product_name ?? `Rekening #${acc.id}` }} &mdash; {{ formatRupiah(acc.balance) }}
            </option>
          </select>
        </div>
        <div class="rounded-lg bg-cream-100 p-3 text-sm flex justify-between">
          <span class="text-primary-500">Estimasi total</span>
          <span class="font-semibold text-primary-800">{{ formatRupiah(estimatedTotal) }}</span>
        </div>
        <button
          type="submit"
          :class="tradeModal === 'buy' ? 'btn-gold' : 'btn-secondary'"
          class="w-full"
          :disabled="buyMutation.isPending.value || sellMutation.isPending.value"
        >
          {{ (buyMutation.isPending.value || sellMutation.isPending.value) ? 'Memproses...' : (tradeModal === 'buy' ? 'Beli Sekarang' : 'Jual Sekarang') }}
        </button>
      </form>
    </FormModal>
  </DashboardShell>
</template>
