<script setup lang="ts">
import { computed, ref } from 'vue';
import DashboardShell from '@/components/DashboardShell.vue';
import Skeleton from '@/components/Skeleton.vue';
import EmptyState from '@/components/EmptyState.vue';
import Pagination from '@/components/Pagination.vue';
import { useTransactionHistory } from '@/composables/useTransactions';
import { formatDate, formatRupiah, jenisLabel } from '@/lib/utils';
import type { TransactionJenis } from '@/types/api';

const page = ref(1);
const jenis = ref<TransactionJenis>('semua');
const date = ref('');

const params = computed(() => ({ page: page.value, jenis: jenis.value, date: date.value }));
const historyQuery = useTransactionHistory(params);

function onFilterChange() {
  page.value = 1;
}
</script>

<template>
  <DashboardShell>
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
      <div>
        <h2 class="font-display text-xl font-semibold text-primary-800">Riwayat Transaksi</h2>
        <p class="text-sm text-primary-500 mt-1">Seluruh mutasi simpanan, cicilan pembiayaan, dan emas digital Anda.</p>
      </div>
      <div class="flex gap-2">
        <select v-model="jenis" class="input !w-auto" @change="onFilterChange">
          <option value="semua">Semua Jenis</option>
          <option value="simpanan">Simpanan</option>
          <option value="pinjaman">Pinjaman</option>
          <option value="emas">Emas</option>
        </select>
        <input v-model="date" type="date" class="input !w-auto" @change="onFilterChange" />
      </div>
    </div>

    <Skeleton v-if="historyQuery.isPending.value" :rows="5" />
    <EmptyState
      v-else-if="!historyQuery.data.value?.transactions.length"
      icon="📜"
      title="Belum ada transaksi"
      description="Transaksi simpanan, cicilan, dan emas Anda akan tercatat di sini."
    />
    <div v-else class="card !p-0 overflow-hidden overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-cream-100 text-left text-xs uppercase tracking-wide text-primary-500">
          <tr>
            <th class="px-4 py-3 whitespace-nowrap">Tanggal</th>
            <th class="px-4 py-3">Deskripsi</th>
            <th class="px-4 py-3 whitespace-nowrap">Jenis</th>
            <th class="px-4 py-3 text-right whitespace-nowrap">Jumlah</th>
            <th class="px-4 py-3 text-right whitespace-nowrap">Saldo</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-primary-50">
          <tr v-for="t in historyQuery.data.value.transactions" :key="t.id">
            <td class="px-4 py-3 text-primary-600 whitespace-nowrap">{{ formatDate(t.created_at) }}</td>
            <td class="px-4 py-3 text-primary-800">{{ t.description }}</td>
            <td class="px-4 py-3 text-primary-500">{{ jenisLabel(t.jenis) }}</td>
            <td
              class="px-4 py-3 text-right font-medium whitespace-nowrap"
              :class="t.direction === 'in' ? 'text-primary-700' : 'text-secondary-700'"
            >
              {{ t.direction === 'in' ? '+' : '-' }}{{ formatRupiah(t.amount) }}
            </td>
            <td class="px-4 py-3 text-right text-primary-800 whitespace-nowrap">{{ formatRupiah(t.saldo) }}</td>
          </tr>
        </tbody>
      </table>
      <div class="px-4 pb-4">
        <Pagination
          :page="historyQuery.data.value.page"
          :per-page="historyQuery.data.value.per_page"
          :total="historyQuery.data.value.total"
          @update:page="(p) => (page = p)"
        />
      </div>
    </div>
  </DashboardShell>
</template>
