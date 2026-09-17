<script setup lang="ts">
import { ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import MarketShell from '@/components/MarketShell.vue';
import EmptyState from '@/components/EmptyState.vue';
import Skeleton from '@/components/Skeleton.vue';
import Pagination from '@/components/Pagination.vue';
import OrderStatusBadge from '@/components/OrderStatusBadge.vue';
import { useMyOrders } from '@/composables/useOrders';
import { ORDER_STATUS, formatDate, formatRupiah } from '@/lib/format';

const status = ref('');
const page = ref(1);
watch(status, () => (page.value = 1));
const ordersQuery = useMyOrders(() => ({ status: status.value, page: page.value }));
</script>

<template>
  <MarketShell>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-2xl font-bold">Pesanan Saya</h1>
      <select v-model="status" class="input !w-auto" aria-label="Filter status">
        <option value="">Semua status</option>
        <option v-for="(v, k) in ORDER_STATUS" :key="k" :value="k">{{ v.label }}</option>
      </select>
    </div>

    <Skeleton v-if="ordersQuery.isPending.value" :rows="3" />
    <EmptyState v-else-if="!ordersQuery.data.value?.items.length" icon="📦" title="Belum ada pesanan" />
    <div v-else class="space-y-3">
      <RouterLink v-for="o in ordersQuery.data.value.items" :key="o.id" :to="{ name: 'order', params: { id: o.id } }" class="card flex flex-wrap items-center justify-between gap-3 !py-4">
        <div>
          <p class="text-sm font-semibold text-primary-800">{{ o.store_name }}</p>
          <p class="text-xs text-primary-500">{{ o.order_number }} · {{ formatDate(o.created_at) }}</p>
        </div>
        <div class="flex items-center gap-4">
          <OrderStatusBadge :status="o.status" />
          <span class="font-display text-lg font-bold text-primary-800">{{ formatRupiah(o.total) }}</span>
        </div>
      </RouterLink>
      <Pagination :page="page" :per-page="ordersQuery.data.value.per_page" :total="ordersQuery.data.value.total" @update:page="(p) => (page = p)" />
    </div>
  </MarketShell>
</template>
