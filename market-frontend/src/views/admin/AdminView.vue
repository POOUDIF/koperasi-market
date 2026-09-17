<script setup lang="ts">
import { ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { toast } from 'vue-sonner';
import MarketShell from '@/components/MarketShell.vue';
import Skeleton from '@/components/Skeleton.vue';
import Pagination from '@/components/Pagination.vue';
import OrderStatusBadge from '@/components/OrderStatusBadge.vue';
import { useAdminOrders, useAdminStores, useSetStoreStatus } from '@/composables/useAdmin';
import { apiErrorMessage } from '@/lib/api';
import { ORDER_STATUS, formatDate, formatRupiah } from '@/lib/format';
import type { Store } from '@/types';

const tab = ref<'stores' | 'orders'>('stores');

const storePage = ref(1);
const storesQuery = useAdminStores(storePage);
const setStatus = useSetStoreStatus();

async function toggle(s: Store) {
  const next = s.status === 'active' ? 'suspended' : 'active';
  if (!confirm(`${next === 'suspended' ? 'Tangguhkan' : 'Aktifkan kembali'} toko "${s.name}"?`)) return;
  try {
    await setStatus.mutateAsync({ id: s.id, status: next });
    toast.success('Status toko diperbarui.');
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Gagal memperbarui status.'));
  }
}

const orderStatus = ref('');
const orderPage = ref(1);
watch(orderStatus, () => (orderPage.value = 1));
const ordersQuery = useAdminOrders(() => ({ status: orderStatus.value, page: orderPage.value }));
</script>

<template>
  <MarketShell>
    <h1 class="mb-6 text-2xl font-bold">Admin Marketplace</h1>
    <nav class="mb-6 flex gap-2 border-b border-primary-100" role="tablist">
      <button v-for="t in ([['stores', 'Toko'], ['orders', 'Pesanan']] as const)" :key="t[0]" role="tab" :aria-selected="tab === t[0]"
        class="-mb-px border-b-2 px-4 py-2 text-sm font-semibold"
        :class="tab === t[0] ? 'border-primary-700 text-primary-800' : 'border-transparent text-primary-500'"
        @click="tab = t[0]">{{ t[1] }}</button>
    </nav>

    <section v-if="tab === 'stores'">
      <Skeleton v-if="storesQuery.isPending.value" :rows="3" />
      <div v-else class="card overflow-x-auto !p-0">
        <table class="w-full text-left text-sm">
          <thead class="border-b border-primary-100 bg-cream-50 text-xs uppercase text-primary-500">
            <tr><th class="px-4 py-3">Toko</th><th class="px-4 py-3">Pemilik</th><th class="px-4 py-3">Produk</th><th class="px-4 py-3">Status</th><th class="px-4 py-3"></th></tr>
          </thead>
          <tbody class="divide-y divide-primary-50">
            <tr v-for="s in storesQuery.data.value?.items" :key="s.id">
              <td class="px-4 py-3"><RouterLink :to="{ name: 'store', params: { slug: s.slug } }" class="font-medium hover:underline">{{ s.name }}</RouterLink><span class="block text-xs text-primary-500">{{ s.city }}</span></td>
              <td class="px-4 py-3">{{ s.owner_name }}<span class="block text-xs text-primary-500">{{ s.owner_email }}</span></td>
              <td class="px-4 py-3">{{ s.product_count }}</td>
              <td class="px-4 py-3"><span class="badge" :class="s.status === 'active' ? 'bg-primary-100 text-primary-700' : 'bg-secondary-100 text-secondary-800'">{{ s.status }}</span></td>
              <td class="px-4 py-3 text-right"><button class="text-sm font-semibold hover:underline" :class="s.status === 'active' ? 'text-secondary-700' : 'text-primary-700'" @click="toggle(s)">{{ s.status === 'active' ? 'Tangguhkan' : 'Aktifkan' }}</button></td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pagination v-if="storesQuery.data.value" :page="storePage" :per-page="storesQuery.data.value.per_page" :total="storesQuery.data.value.total" @update:page="(p) => (storePage = p)" />
    </section>

    <section v-else>
      <select v-model="orderStatus" class="input mb-4 !w-auto" aria-label="Filter status">
        <option value="">Semua status</option>
        <option v-for="(v, k) in ORDER_STATUS" :key="k" :value="k">{{ v.label }}</option>
      </select>
      <Skeleton v-if="ordersQuery.isPending.value" :rows="3" />
      <div v-else class="card overflow-x-auto !p-0">
        <table class="w-full text-left text-sm">
          <thead class="border-b border-primary-100 bg-cream-50 text-xs uppercase text-primary-500">
            <tr><th class="px-4 py-3">Pesanan</th><th class="px-4 py-3">Toko / Pembeli</th><th class="px-4 py-3">Total</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Dana</th></tr>
          </thead>
          <tbody class="divide-y divide-primary-50">
            <tr v-for="o in ordersQuery.data.value?.items" :key="o.id">
              <td class="px-4 py-3">{{ o.order_number }}<span class="block text-xs text-primary-500">{{ formatDate(o.created_at) }}</span></td>
              <td class="px-4 py-3">{{ o.store_name }}<span class="block text-xs text-primary-500">{{ o.buyer_name }}</span></td>
              <td class="px-4 py-3">{{ formatRupiah(o.total) }}</td>
              <td class="px-4 py-3"><OrderStatusBadge :status="o.status" /></td>
              <td class="px-4 py-3 text-xs">
                <span v-if="o.payout_action !== 'none'" :class="o.payout_status === 'failed' ? 'font-semibold text-secondary-700' : ''">{{ o.payout_action }} · {{ o.payout_status }}</span>
                <span v-else>-</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pagination v-if="ordersQuery.data.value" :page="orderPage" :per-page="ordersQuery.data.value.per_page" :total="ordersQuery.data.value.total" @update:page="(p) => (orderPage = p)" />
    </section>
  </MarketShell>
</template>
