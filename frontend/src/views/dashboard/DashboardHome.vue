<script setup lang="ts">
import { computed } from 'vue';
import DashboardShell from '@/components/DashboardShell.vue';
import Skeleton from '@/components/Skeleton.vue';
import { useAuthStore } from '@/stores/auth';
import { useSavingsAccounts } from '@/composables/useSavings';
import { useGoldHolding } from '@/composables/useGold';
import { useFinancings } from '@/composables/useFinancing';
import { formatGram, formatRupiah } from '@/lib/utils';

const authStore = useAuthStore();
const accountsQuery = useSavingsAccounts();
const holdingQuery = useGoldHolding();
const financingsQuery = useFinancings(1);

const totalBalance = computed(() => (accountsQuery.data.value ?? []).reduce((sum, a) => sum + a.balance, 0));
const activeFinancingCount = computed(
  () => (financingsQuery.data.value?.financings ?? []).filter((f) => f.status === 'approved').length,
);

const modules = [
  { to: '/dashboard/savings', title: 'Simpanan', desc: 'Kelola rekening Wadiah & Mudharabah, setor, dan tarik dana.', icon: '🏦', tone: 'primary' },
  { to: '/dashboard/financing', title: 'Pembiayaan', desc: 'Ajukan dan pantau pembiayaan Murabahah Anda.', icon: '📋', tone: 'gold' },
  { to: '/dashboard/gold', title: 'Emas Digital', desc: 'Beli, jual, dan pantau kepemilikan emas digital.', icon: '🥇', tone: 'secondary' },
  { to: '/dashboard/kyc', title: 'Profil KYC', desc: 'Lengkapi data diri untuk kelancaran pengajuan pembiayaan.', icon: '🪪', tone: 'primary' },
] as const;
</script>

<template>
  <DashboardShell>
    <section class="mb-8 rounded-xl2 bg-gradient-to-br from-primary-700 to-primary-800 p-6 sm:p-8 text-white shadow-card-hover relative overflow-hidden">
      <div class="absolute -right-10 -top-10 h-40 w-40 rounded-full bg-gold-400/10" />
      <div class="absolute -right-4 bottom-0 h-24 w-24 rounded-full bg-gold-400/10" />
      <p class="text-sm text-primary-100/80">Selamat datang,</p>
      <h2 class="font-display text-2xl sm:text-3xl font-bold mt-1 text-white">{{ authStore.user?.nama_lengkap ?? '...' }}</h2>
      <p class="text-sm text-primary-100/70 mt-2 max-w-lg">
        Bersama membangun kesejahteraan anggota koperasi &mdash; simpan, biayai, dan investasikan emas digital dalam satu tempat.
      </p>
    </section>

    <section class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
      <div class="card">
        <p class="text-xs font-medium text-primary-500 uppercase tracking-wide">Total Saldo Simpanan</p>
        <Skeleton v-if="accountsQuery.isPending.value" :rows="1" />
        <p v-else class="mt-1 font-display text-2xl font-bold text-primary-800">{{ formatRupiah(totalBalance) }}</p>
      </div>
      <div class="card">
        <p class="text-xs font-medium text-primary-500 uppercase tracking-wide">Kepemilikan Emas</p>
        <Skeleton v-if="holdingQuery.isPending.value" :rows="1" />
        <p v-else class="mt-1 font-display text-2xl font-bold text-gold-700">{{ formatGram(holdingQuery.data.value?.net_gram ?? 0) }}</p>
      </div>
      <div class="card">
        <p class="text-xs font-medium text-primary-500 uppercase tracking-wide">Pembiayaan Berjalan</p>
        <Skeleton v-if="financingsQuery.isPending.value" :rows="1" />
        <p v-else class="mt-1 font-display text-2xl font-bold text-secondary-700">{{ activeFinancingCount }}</p>
      </div>
    </section>

    <h3 class="font-display text-lg font-semibold text-primary-800 mb-4">Modul Layanan</h3>
    <section class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <RouterLink v-for="m in modules" :key="m.to" :to="m.to" class="card flex items-start gap-4 group">
        <div
          class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl text-2xl"
          :class="{
            primary: 'bg-primary-100',
            gold: 'bg-gold-100',
            secondary: 'bg-secondary-100',
          }[m.tone]"
        >
          {{ m.icon }}
        </div>
        <div>
          <p class="font-semibold text-primary-800 group-hover:text-primary-600">{{ m.title }}</p>
          <p class="text-sm text-primary-500 mt-0.5">{{ m.desc }}</p>
        </div>
      </RouterLink>
    </section>
  </DashboardShell>
</template>
