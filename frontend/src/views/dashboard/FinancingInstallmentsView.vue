<script setup lang="ts">
import { ref, computed } from 'vue';
import { toast } from 'vue-sonner';
import { useRouter } from 'vue-router';
import DashboardShell from '@/components/DashboardShell.vue';
import Skeleton from '@/components/Skeleton.vue';
import EmptyState from '@/components/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import FormModal from '@/components/FormModal.vue';
import { useInstallments, usePayInstallment } from '@/composables/useFinancing';
import { useSavingsAccounts } from '@/composables/useSavings';
import { apiErrorMessage } from '@/lib/api';
import { formatDateShort, formatRupiah } from '@/lib/utils';
import type { FinancingInstallment } from '@/types/api';

const props = defineProps<{ id: number }>();
const router = useRouter();

const installmentsQuery = useInstallments(props.id);
const accountsQuery = useSavingsAccounts();
const payMutation = usePayInstallment();

const payModal = ref(false);
const selectedInstallment = ref<FinancingInstallment | null>(null);
const selectedAccountId = ref<number | null>(null);

function openPayModal(inst: FinancingInstallment) {
  selectedInstallment.value = inst;
  selectedAccountId.value = accountsQuery.data.value?.[0]?.id ?? null;
  payModal.value = true;
}

async function handlePay() {
  if (!selectedInstallment.value || !selectedAccountId.value) return;
  try {
    const result = await payMutation.mutateAsync({
      installmentId: selectedInstallment.value.id,
      savings_account_id: selectedAccountId.value,
    });
    toast.success(result.financing_settled ? 'Cicilan terakhir lunas! Pembiayaan selesai.' : 'Cicilan berhasil dibayar.');
    payModal.value = false;
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Gagal membayar cicilan.'));
  }
}

function isOverdue(inst: FinancingInstallment) {
  return inst.status === 'unpaid' && new Date(inst.due_date) < new Date();
}

const installments = computed(() => installmentsQuery.data.value ?? []);
</script>

<template>
  <DashboardShell>
    <button class="text-sm text-primary-600 hover:underline mb-4 inline-flex items-center gap-1" @click="router.push({ name: 'financing' })">
      &larr; Kembali ke Pembiayaan
    </button>
    <h2 class="font-display text-xl font-semibold text-primary-800 mb-1">Jadwal Angsuran</h2>
    <p class="text-sm text-primary-500 mb-6">Pembiayaan #{{ id }}</p>

    <Skeleton v-if="installmentsQuery.isPending.value" :rows="4" />
    <EmptyState v-else-if="!installments.length" icon="🧾" title="Belum ada jadwal angsuran" />

    <div v-else class="card !p-0 overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-cream-100 text-left text-xs uppercase tracking-wide text-primary-500">
          <tr>
            <th class="px-4 py-3">No.</th>
            <th class="px-4 py-3">Jatuh Tempo</th>
            <th class="px-4 py-3">Nominal</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3">Tgl. Bayar</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-primary-50">
          <tr v-for="inst in installments" :key="inst.id" :class="isOverdue(inst) && 'bg-secondary-50/50'">
            <td class="px-4 py-3 text-primary-600">{{ inst.installment_number }}</td>
            <td class="px-4 py-3" :class="isOverdue(inst) ? 'text-secondary-700 font-medium' : 'text-primary-600'">
              {{ formatDateShort(inst.due_date) }}
              <span v-if="isOverdue(inst)" class="text-xs">(jatuh tempo)</span>
            </td>
            <td class="px-4 py-3 font-medium text-primary-800">{{ formatRupiah(inst.amount_due) }}</td>
            <td class="px-4 py-3"><StatusBadge :status="inst.status" /></td>
            <td class="px-4 py-3 text-primary-500">{{ inst.paid_at ? formatDateShort(inst.paid_at) : '-' }}</td>
            <td class="px-4 py-3 text-right">
              <button v-if="inst.status === 'unpaid'" class="btn-gold !py-1.5 !px-3 text-xs" @click="openPayModal(inst)">Bayar</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <FormModal :open="payModal" title="Konfirmasi Pembayaran Cicilan" @close="payModal = false">
      <div v-if="selectedInstallment" class="space-y-4">
        <div class="rounded-lg bg-cream-100 p-3 text-sm">
          <p class="text-primary-500">Cicilan ke-{{ selectedInstallment.installment_number }}</p>
          <p class="font-display text-xl font-bold text-primary-800">{{ formatRupiah(selectedInstallment.amount_due) }}</p>
        </div>
        <div>
          <label class="label">Bayar dari Rekening</label>
          <select v-model.number="selectedAccountId" class="input" required>
            <option v-for="acc in accountsQuery.data.value" :key="acc.id" :value="acc.id">
              {{ acc.product_name ?? `Rekening #${acc.id}` }} &mdash; {{ formatRupiah(acc.balance) }}
            </option>
          </select>
        </div>
        <button class="btn-gold w-full" :disabled="payMutation.isPending.value" @click="handlePay">
          {{ payMutation.isPending.value ? 'Memproses...' : 'Bayar Sekarang' }}
        </button>
      </div>
    </FormModal>
  </DashboardShell>
</template>
