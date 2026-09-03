<script setup lang="ts">
import { ref, computed, reactive } from 'vue';
import { toast } from 'vue-sonner';
import DashboardShell from '@/components/DashboardShell.vue';
import Skeleton from '@/components/Skeleton.vue';
import EmptyState from '@/components/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import FormModal from '@/components/FormModal.vue';
import Pagination from '@/components/Pagination.vue';
import {
  useDeposit,
  useDepositRequests,
  useOpenSavingsAccount,
  useSavingsAccounts,
  useSavingsProducts,
  useWithdraw,
  useWithdrawRequests,
} from '@/composables/useSavings';
import { apiErrorMessage } from '@/lib/api';
import { formatDate, formatRupiah } from '@/lib/utils';

const accountsQuery = useSavingsAccounts();
const productsQuery = useSavingsProducts();
const openAccountMutation = useOpenSavingsAccount();
const depositMutation = useDeposit();
const withdrawMutation = useWithdraw();

const activeTab = ref<'deposit' | 'withdraw'>('deposit');
const depositPage = ref(1);
const withdrawPage = ref(1);
const depositRequestsQuery = useDepositRequests(depositPage);
const withdrawRequestsQuery = useWithdrawRequests(withdrawPage);

// ------------------------------------------------------------- buka rekening
const openModal = ref(false);
const selectedProductId = ref<number | null>(null);

async function handleOpenAccount() {
  if (!selectedProductId.value) return;
  try {
    await openAccountMutation.mutateAsync(selectedProductId.value);
    toast.success('Rekening berhasil dibuka.');
    openModal.value = false;
    selectedProductId.value = null;
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Gagal membuka rekening.'));
  }
}

// ------------------------------------------------------------------- setor
const depositModal = ref(false);
const depositForm = reactive({ account_id: 0, amount: 0, payment_method: '', reference_id: '' });

function openDepositModal() {
  depositForm.account_id = accountsQuery.data.value?.[0]?.id ?? 0;
  depositForm.amount = 0;
  depositForm.payment_method = '';
  depositForm.reference_id = '';
  depositModal.value = true;
}

async function handleDeposit() {
  try {
    await depositMutation.mutateAsync({
      account_id: depositForm.account_id,
      amount: depositForm.amount,
      payment_method: depositForm.payment_method,
      reference_id: depositForm.reference_id || undefined,
    });
    toast.success('Permohonan setoran terkirim, menunggu verifikasi admin.');
    depositModal.value = false;
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Gagal mengajukan setoran.'));
  }
}

// -------------------------------------------------------------------- tarik
const withdrawModal = ref(false);
const withdrawForm = reactive({ account_id: 0, amount: 0, destination_account: '', reference_id: '' });

function openWithdrawModal() {
  withdrawForm.account_id = accountsQuery.data.value?.[0]?.id ?? 0;
  withdrawForm.amount = 0;
  withdrawForm.destination_account = '';
  withdrawForm.reference_id = '';
  withdrawModal.value = true;
}

async function handleWithdraw() {
  try {
    await withdrawMutation.mutateAsync({
      account_id: withdrawForm.account_id,
      amount: withdrawForm.amount,
      destination_account: withdrawForm.destination_account,
      reference_id: withdrawForm.reference_id || undefined,
    });
    toast.success('Permohonan penarikan terkirim, menunggu verifikasi admin.');
    withdrawModal.value = false;
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Gagal mengajukan penarikan.'));
  }
}

const hasAccounts = computed(() => (accountsQuery.data.value ?? []).length > 0);
</script>

<template>
  <DashboardShell>
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
      <div>
        <h2 class="font-display text-xl font-semibold text-primary-800">Simpanan Syariah</h2>
        <p class="text-sm text-primary-500 mt-1">Rekening Wadiah &amp; Mudharabah Anda.</p>
      </div>
      <div class="flex gap-2">
        <button class="btn-secondary" :disabled="!hasAccounts" @click="openWithdrawModal">Tarik Dana</button>
        <button class="btn-gold" :disabled="!hasAccounts" @click="openDepositModal">Setor Dana</button>
        <button class="btn-primary" @click="openModal = true">+ Buka Rekening</button>
      </div>
    </div>

    <Skeleton v-if="accountsQuery.isPending.value" :rows="2" />
    <EmptyState v-else-if="!hasAccounts" icon="🏦" title="Belum ada rekening" description="Buka rekening simpanan pertama Anda untuk mulai menabung.">
      <template #action>
        <button class="btn-primary" @click="openModal = true">Buka Rekening</button>
      </template>
    </EmptyState>

    <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-10">
      <div v-for="acc in accountsQuery.data.value" :key="acc.id" class="card">
        <div class="flex items-start justify-between">
          <div>
            <p class="text-xs font-medium text-primary-500 uppercase tracking-wide">{{ acc.akad_type ?? 'Simpanan' }}</p>
            <p class="font-semibold text-primary-800">{{ acc.product_name ?? `Rekening #${acc.id}` }}</p>
          </div>
          <StatusBadge :status="acc.status" />
        </div>
        <p class="font-display text-2xl font-bold text-primary-800 mt-4">{{ formatRupiah(acc.balance) }}</p>
        <p class="text-xs text-primary-400 mt-1">Dibuka {{ formatDate(acc.created_at) }}</p>
      </div>
    </div>

    <!-- Riwayat -->
    <div class="mb-4 flex gap-2 border-b border-primary-100">
      <button
        class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition"
        :class="activeTab === 'deposit' ? 'border-primary-700 text-primary-800' : 'border-transparent text-primary-400 hover:text-primary-600'"
        @click="activeTab = 'deposit'"
      >
        Riwayat Setoran
      </button>
      <button
        class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition"
        :class="activeTab === 'withdraw' ? 'border-primary-700 text-primary-800' : 'border-transparent text-primary-400 hover:text-primary-600'"
        @click="activeTab = 'withdraw'"
      >
        Riwayat Penarikan
      </button>
    </div>

    <div v-if="activeTab === 'deposit'">
      <Skeleton v-if="depositRequestsQuery.isPending.value" />
      <EmptyState v-else-if="!depositRequestsQuery.data.value?.deposit_requests.length" icon="📥" title="Belum ada permohonan setoran" />
      <div v-else class="card !p-0 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-cream-100 text-left text-xs uppercase tracking-wide text-primary-500">
            <tr>
              <th class="px-4 py-3">Tanggal</th>
              <th class="px-4 py-3">Nominal</th>
              <th class="px-4 py-3">Metode</th>
              <th class="px-4 py-3">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-primary-50">
            <tr v-for="r in depositRequestsQuery.data.value?.deposit_requests" :key="r.id">
              <td class="px-4 py-3 text-primary-600">{{ formatDate(r.created_at) }}</td>
              <td class="px-4 py-3 font-medium text-primary-800">{{ formatRupiah(r.amount) }}</td>
              <td class="px-4 py-3 text-primary-600">{{ r.payment_method }}</td>
              <td class="px-4 py-3"><StatusBadge :status="r.status" /></td>
            </tr>
          </tbody>
        </table>
        <div class="px-4 pb-4">
          <Pagination
            :page="depositRequestsQuery.data.value?.page ?? 1"
            :per-page="depositRequestsQuery.data.value?.per_page ?? 10"
            :total="depositRequestsQuery.data.value?.total ?? 0"
            @update:page="(p) => (depositPage = p)"
          />
        </div>
      </div>
    </div>

    <div v-else>
      <Skeleton v-if="withdrawRequestsQuery.isPending.value" />
      <EmptyState v-else-if="!withdrawRequestsQuery.data.value?.withdraw_requests.length" icon="📤" title="Belum ada permohonan penarikan" />
      <div v-else class="card !p-0 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-cream-100 text-left text-xs uppercase tracking-wide text-primary-500">
            <tr>
              <th class="px-4 py-3">Tanggal</th>
              <th class="px-4 py-3">Nominal</th>
              <th class="px-4 py-3">Tujuan</th>
              <th class="px-4 py-3">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-primary-50">
            <tr v-for="r in withdrawRequestsQuery.data.value?.withdraw_requests" :key="r.id">
              <td class="px-4 py-3 text-primary-600">{{ formatDate(r.created_at) }}</td>
              <td class="px-4 py-3 font-medium text-primary-800">{{ formatRupiah(r.amount) }}</td>
              <td class="px-4 py-3 text-primary-600">{{ r.reference_id || '-' }}</td>
              <td class="px-4 py-3"><StatusBadge :status="r.status" /></td>
            </tr>
          </tbody>
        </table>
        <div class="px-4 pb-4">
          <Pagination
            :page="withdrawRequestsQuery.data.value?.page ?? 1"
            :per-page="withdrawRequestsQuery.data.value?.per_page ?? 10"
            :total="withdrawRequestsQuery.data.value?.total ?? 0"
            @update:page="(p) => (withdrawPage = p)"
          />
        </div>
      </div>
    </div>

    <!-- Modal: Buka Rekening -->
    <FormModal :open="openModal" title="Buka Rekening Baru" description="Pilih produk simpanan yang ingin dibuka." @close="openModal = false">
      <form class="space-y-4" @submit.prevent="handleOpenAccount">
        <div v-for="p in productsQuery.data.value" :key="p.id" class="flex items-start gap-3 rounded-lg border border-primary-100 p-3 cursor-pointer hover:bg-primary-50" @click="selectedProductId = p.id">
          <input type="radio" :value="p.id" v-model="selectedProductId" class="mt-1" />
          <div>
            <p class="font-medium text-primary-800">{{ p.name }} <span class="text-xs text-primary-400">({{ p.akad_type }})</span></p>
            <p class="text-xs text-primary-500">Min. setoran {{ formatRupiah(p.min_deposit) }}</p>
          </div>
        </div>
        <button type="submit" class="btn-primary w-full" :disabled="!selectedProductId || openAccountMutation.isPending.value">
          {{ openAccountMutation.isPending.value ? 'Membuka...' : 'Buka Rekening' }}
        </button>
      </form>
    </FormModal>

    <!-- Modal: Setor Dana -->
    <FormModal :open="depositModal" title="Setor Dana" description="Ajukan setoran, akan diverifikasi admin sebelum saldo bertambah." @close="depositModal = false">
      <form class="space-y-4" @submit.prevent="handleDeposit">
        <div>
          <label class="label">Rekening Tujuan</label>
          <select v-model.number="depositForm.account_id" class="input" required>
            <option v-for="acc in accountsQuery.data.value" :key="acc.id" :value="acc.id">
              {{ acc.product_name ?? `Rekening #${acc.id}` }} &mdash; {{ formatRupiah(acc.balance) }}
            </option>
          </select>
        </div>
        <div>
          <label class="label">Nominal (Rp)</label>
          <input v-model.number="depositForm.amount" type="number" min="1" required class="input" />
        </div>
        <div>
          <label class="label">Metode Pembayaran</label>
          <input v-model="depositForm.payment_method" type="text" required placeholder="mis. Transfer BCA" class="input" />
        </div>
        <div>
          <label class="label">No. Referensi (opsional)</label>
          <input v-model="depositForm.reference_id" type="text" placeholder="No. bukti transfer" class="input" />
        </div>
        <button type="submit" class="btn-gold w-full" :disabled="depositMutation.isPending.value">
          {{ depositMutation.isPending.value ? 'Mengirim...' : 'Ajukan Setoran' }}
        </button>
      </form>
    </FormModal>

    <!-- Modal: Tarik Dana -->
    <FormModal :open="withdrawModal" title="Tarik Dana" description="Ajukan penarikan, saldo berkurang setelah disetujui admin." @close="withdrawModal = false">
      <form class="space-y-4" @submit.prevent="handleWithdraw">
        <div>
          <label class="label">Rekening Sumber</label>
          <select v-model.number="withdrawForm.account_id" class="input" required>
            <option v-for="acc in accountsQuery.data.value" :key="acc.id" :value="acc.id">
              {{ acc.product_name ?? `Rekening #${acc.id}` }} &mdash; {{ formatRupiah(acc.balance) }}
            </option>
          </select>
        </div>
        <div>
          <label class="label">Nominal (Rp)</label>
          <input v-model.number="withdrawForm.amount" type="number" min="1" required class="input" />
        </div>
        <div>
          <label class="label">Rekening Tujuan Pencairan</label>
          <input v-model="withdrawForm.destination_account" type="text" required placeholder="Bank & No. Rekening tujuan" class="input" />
        </div>
        <div>
          <label class="label">No. Referensi (opsional)</label>
          <input v-model="withdrawForm.reference_id" type="text" class="input" />
        </div>
        <button type="submit" class="btn-secondary w-full" :disabled="withdrawMutation.isPending.value">
          {{ withdrawMutation.isPending.value ? 'Mengirim...' : 'Ajukan Penarikan' }}
        </button>
      </form>
    </FormModal>
  </DashboardShell>
</template>
