<script setup lang="ts">
import DashboardShell from '@/components/DashboardShell.vue';
import Skeleton from '@/components/Skeleton.vue';
import { useAdminDepositRequests, useAdminWithdrawRequests } from '@/composables/useAdmin';

const depositsQuery = useAdminDepositRequests(1, 'pending');
const withdrawalsQuery = useAdminWithdrawRequests(1, 'pending');

const sections = [
  { to: '/dashboard/admin/deposits', title: 'Verifikasi Setoran', desc: 'Setujui atau tolak permohonan setoran anggota.', icon: '📥' },
  { to: '/dashboard/admin/withdrawals', title: 'Verifikasi Penarikan', desc: 'Setujui atau tolak permohonan penarikan dana.', icon: '📤' },
  { to: '/dashboard/admin/financing', title: 'Review Pembiayaan', desc: 'Tinjau pengajuan pembiayaan Murabahah anggota.', icon: '✅' },
  { to: '/dashboard/admin/gold-price', title: 'Harga Emas', desc: 'Atur harga beli & jual emas per gram.', icon: '💰' },
  { to: '/dashboard/admin/users', title: 'Manajemen Anggota', desc: 'Lihat daftar seluruh anggota koperasi.', icon: '👥' },
  { to: '/dashboard/admin/transactions', title: 'Riwayat Transaksi', desc: 'Pantau transaksi pembiayaan, emas, dan simpanan.', icon: '📊' },
] as const;
</script>

<template>
  <DashboardShell>
    <div class="mb-8">
      <h2 class="font-display text-xl font-semibold text-primary-800">Panel Admin</h2>
      <p class="text-sm text-primary-500 mt-1">Ringkasan aktivitas yang butuh perhatian Anda.</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
      <RouterLink to="/dashboard/admin/deposits" class="card bg-secondary-700 text-white border-none hover:bg-secondary-800">
        <p class="text-sm text-secondary-100">Setoran Menunggu Review</p>
        <Skeleton v-if="depositsQuery.isPending.value" :rows="1" />
        <p v-else class="font-display text-3xl font-bold mt-1">{{ depositsQuery.data.value?.total ?? 0 }}</p>
      </RouterLink>
      <RouterLink to="/dashboard/admin/withdrawals" class="card bg-primary-700 text-white border-none hover:bg-primary-800">
        <p class="text-sm text-primary-100">Penarikan Menunggu Review</p>
        <Skeleton v-if="withdrawalsQuery.isPending.value" :rows="1" />
        <p v-else class="font-display text-3xl font-bold mt-1">{{ withdrawalsQuery.data.value?.total ?? 0 }}</p>
      </RouterLink>
    </div>

    <h3 class="font-display text-lg font-semibold text-primary-800 mb-4">Menu Admin</h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <RouterLink v-for="s in sections" :key="s.to" :to="s.to" class="card flex items-start gap-4 group">
        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-secondary-100 text-2xl">{{ s.icon }}</div>
        <div>
          <p class="font-semibold text-primary-800 group-hover:text-primary-600">{{ s.title }}</p>
          <p class="text-sm text-primary-500 mt-0.5">{{ s.desc }}</p>
        </div>
      </RouterLink>
    </div>
  </DashboardShell>
</template>
