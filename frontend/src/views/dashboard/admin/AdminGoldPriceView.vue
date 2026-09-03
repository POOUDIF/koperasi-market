<script setup lang="ts">
import { reactive, watch } from 'vue';
import { toast } from 'vue-sonner';
import DashboardShell from '@/components/DashboardShell.vue';
import Skeleton from '@/components/Skeleton.vue';
import { useGoldPrice } from '@/composables/useGold';
import { useAdminSetGoldPrice } from '@/composables/useAdmin';
import { apiErrorMessage } from '@/lib/api';
import { formatDate, formatRupiah } from '@/lib/utils';

const priceQuery = useGoldPrice();
const setPriceMutation = useAdminSetGoldPrice();

const form = reactive({ buy_price_per_gram: 0, sell_price_per_gram: 0 });

watch(
  () => priceQuery.data.value,
  (data) => {
    if (!data) return;
    form.buy_price_per_gram = data.buy_price_per_gram;
    form.sell_price_per_gram = data.sell_price_per_gram;
  },
  { immediate: true },
);

async function handleSubmit() {
  try {
    await setPriceMutation.mutateAsync({ ...form });
    toast.success('Harga emas berhasil diperbarui.');
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Gagal memperbarui harga emas.'));
  }
}
</script>

<template>
  <DashboardShell>
    <div class="max-w-lg">
      <div class="mb-6">
        <h2 class="font-display text-xl font-semibold text-primary-800">Harga Emas</h2>
        <p class="text-sm text-primary-500 mt-1">Perubahan langsung menginvalidasi cache dan berlaku untuk seluruh anggota.</p>
      </div>

      <div v-if="priceQuery.data.value" class="mb-4 text-xs text-primary-400">
        Harga saat ini terakhir diperbarui: {{ formatDate(priceQuery.data.value.updated_at) }}
      </div>

      <Skeleton v-if="priceQuery.isPending.value" :rows="3" />

      <form v-else class="card space-y-5" @submit.prevent="handleSubmit">
        <div>
          <label class="label">Harga Beli Anggota (Rp/gram)</label>
          <input v-model.number="form.buy_price_per_gram" type="number" min="1" required class="input" />
          <p class="mt-1 text-xs text-primary-400">Harga saat anggota membeli emas.</p>
        </div>
        <div>
          <label class="label">Harga Jual Anggota (Rp/gram)</label>
          <input v-model.number="form.sell_price_per_gram" type="number" min="1" required class="input" />
          <p class="mt-1 text-xs text-primary-400">Harga saat anggota menjual emas &mdash; harus &le; harga beli.</p>
        </div>
        <div v-if="form.sell_price_per_gram > form.buy_price_per_gram" class="flex items-start gap-2.5 rounded-lg bg-secondary-50 border border-secondary-200 px-4 py-3 text-sm text-secondary-800">
          <span>⚠️</span><span>Harga jual tidak boleh lebih besar dari harga beli.</span>
        </div>
        <button type="submit" class="btn-gold w-full" :disabled="setPriceMutation.isPending.value || form.sell_price_per_gram > form.buy_price_per_gram">
          {{ setPriceMutation.isPending.value ? 'Menyimpan...' : 'Perbarui Harga' }}
        </button>
      </form>

      <div class="mt-6 grid grid-cols-2 gap-4">
        <div class="card">
          <p class="text-xs text-primary-500 uppercase">Harga Beli Aktif</p>
          <p class="font-display text-xl font-bold text-primary-800 mt-1">{{ formatRupiah(priceQuery.data.value?.buy_price_per_gram ?? 0) }}</p>
        </div>
        <div class="card">
          <p class="text-xs text-primary-500 uppercase">Harga Jual Aktif</p>
          <p class="font-display text-xl font-bold text-primary-800 mt-1">{{ formatRupiah(priceQuery.data.value?.sell_price_per_gram ?? 0) }}</p>
        </div>
      </div>
    </div>
  </DashboardShell>
</template>
