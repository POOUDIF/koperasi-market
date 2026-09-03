<script setup lang="ts">
import { ref, computed } from 'vue';
import DashboardShell from '@/components/DashboardShell.vue';
import Skeleton from '@/components/Skeleton.vue';
import EmptyState from '@/components/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import Pagination from '@/components/Pagination.vue';
import { useAdminFinancings, useAdminTxGold, useAdminTxSaving } from '@/composables/useAdmin';
import { formatDate, formatGram, formatRupiah } from '@/lib/utils';

type Tab = 'financing' | 'gold' | 'saving';
const tab = ref<Tab>('financing');

const financingPage = ref(1);
const goldPage = ref(1);
const savingPage = ref(1);

const financingQuery = useAdminFinancings(financingPage);
const goldQuery = useAdminTxGold(goldPage);
const savingQuery = useAdminTxSaving(savingPage);

const tabs: { key: Tab; label: string }[] = [
  { key: 'financing', label: 'Pembiayaan' },
  { key: 'gold', label: 'Emas' },
  { key: 'saving', label: 'Simpanan' },
];

const savingRows = computed(() => savingQuery.data.value?.transactions ?? []);
</script>

<template>
  <DashboardShell>
    <div class="mb-6">
      <h2 class="font-display text-xl font-semibold text-primary-800">Riwayat Transaksi</h2>
      <p class="text-sm text-primary-500 mt-1">Pantau seluruh transaksi lintas modul.</p>
    </div>

    <div class="mb-4 flex gap-2 border-b border-primary-100">
      <button
        v-for="t in tabs"
        :key="t.key"
        class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition"
        :class="tab === t.key ? 'border-primary-700 text-primary-800' : 'border-transparent text-primary-400 hover:text-primary-600'"
        @click="tab = t.key"
      >
        {{ t.label }}
      </button>
    </div>

    <!-- Pembiayaan -->
    <div v-if="tab === 'financing'">
      <Skeleton v-if="financingQuery.isPending.value" :rows="4" />
      <EmptyState v-else-if="!financingQuery.data.value?.financings.length" icon="📋" title="Belum ada transaksi pembiayaan" />
      <div v-else class="card !p-0 overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-cream-100 text-left text-xs uppercase tracking-wide text-primary-500">
            <tr><th class="px-4 py-3">No. Akad</th><th class="px-4 py-3">Pokok</th><th class="px-4 py-3">Total</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Tanggal</th></tr>
          </thead>
          <tbody class="divide-y divide-primary-50">
            <tr v-for="f in financingQuery.data.value?.financings" :key="f.id">
              <td class="px-4 py-3 font-mono text-xs text-primary-500">{{ f.financing_number }}</td>
              <td class="px-4 py-3 font-medium text-primary-800">{{ formatRupiah(f.principal_amount) }}</td>
              <td class="px-4 py-3 text-primary-600">{{ formatRupiah(f.total_payable) }}</td>
              <td class="px-4 py-3"><StatusBadge :status="f.status" /></td>
              <td class="px-4 py-3 text-primary-500">{{ formatDate(f.created_at) }}</td>
            </tr>
          </tbody>
        </table>
        <div class="px-4 pb-4">
          <Pagination :page="financingQuery.data.value?.page ?? 1" :per-page="financingQuery.data.value?.per_page ?? 10" :total="financingQuery.data.value?.total ?? 0" @update:page="(p) => (financingPage = p)" />
        </div>
      </div>
    </div>

    <!-- Emas -->
    <div v-else-if="tab === 'gold'">
      <Skeleton v-if="goldQuery.isPending.value" :rows="4" />
      <EmptyState v-else-if="!goldQuery.data.value?.transactions.length" icon="🥇" title="Belum ada transaksi emas" />
      <div v-else class="card !p-0 overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-cream-100 text-left text-xs uppercase tracking-wide text-primary-500">
            <tr><th class="px-4 py-3">Tipe</th><th class="px-4 py-3">Gram</th><th class="px-4 py-3">Total</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Tanggal</th></tr>
          </thead>
          <tbody class="divide-y divide-primary-50">
            <tr v-for="tx in goldQuery.data.value?.transactions" :key="tx.id">
              <td class="px-4 py-3 font-medium" :class="tx.type === 'buy' ? 'text-primary-700' : 'text-secondary-700'">{{ tx.type === 'buy' ? 'Beli' : 'Jual' }}</td>
              <td class="px-4 py-3 text-primary-600">{{ formatGram(tx.gram_amount) }}</td>
              <td class="px-4 py-3 font-medium text-primary-800">{{ formatRupiah(tx.total_rupiah) }}</td>
              <td class="px-4 py-3"><StatusBadge :status="tx.status" /></td>
              <td class="px-4 py-3 text-primary-500">{{ formatDate(tx.created_at) }}</td>
            </tr>
          </tbody>
        </table>
        <div class="px-4 pb-4">
          <Pagination :page="goldQuery.data.value?.page ?? 1" :per-page="goldQuery.data.value?.per_page ?? 10" :total="goldQuery.data.value?.total ?? 0" @update:page="(p) => (goldPage = p)" />
        </div>
      </div>
    </div>

    <!-- Simpanan -->
    <div v-else>
      <Skeleton v-if="savingQuery.isPending.value" :rows="4" />
      <EmptyState v-else-if="!savingRows.length" icon="🏦" title="Belum ada transaksi simpanan" />
      <div v-else class="card !p-0 overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-cream-100 text-left text-xs uppercase tracking-wide text-primary-500">
            <tr><th class="px-4 py-3">Rekening</th><th class="px-4 py-3">Tipe</th><th class="px-4 py-3">Nominal</th><th class="px-4 py-3">Referensi</th><th class="px-4 py-3">Tanggal</th></tr>
          </thead>
          <tbody class="divide-y divide-primary-50">
            <tr v-for="row in savingRows" :key="row.id">
              <td class="px-4 py-3 text-primary-600">#{{ row.account_id }}</td>
              <td class="px-4 py-3 font-medium" :class="row.type === 'deposit' ? 'text-primary-700' : 'text-secondary-700'">{{ row.type === 'deposit' ? 'Setoran' : 'Penarikan' }}</td>
              <td class="px-4 py-3 font-medium text-primary-800">{{ formatRupiah(row.amount) }}</td>
              <td class="px-4 py-3 text-primary-600">{{ row.reference_id || '-' }}</td>
              <td class="px-4 py-3 text-primary-500">{{ formatDate(row.created_at) }}</td>
            </tr>
          </tbody>
        </table>
        <div class="px-4 pb-4">
          <Pagination :page="savingQuery.data.value?.page ?? 1" :per-page="savingQuery.data.value?.per_page ?? 10" :total="savingQuery.data.value?.total ?? 0" @update:page="(p) => (savingPage = p)" />
        </div>
      </div>
    </div>
  </DashboardShell>
</template>
