<script setup lang="ts">
import { ref, computed } from 'vue';
import { toast } from 'vue-sonner';
import DashboardShell from '@/components/DashboardShell.vue';
import Skeleton from '@/components/Skeleton.vue';
import EmptyState from '@/components/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import ConfirmModal from '@/components/ConfirmModal.vue';
import Pagination from '@/components/Pagination.vue';
import { useAdminDepositRequests, useAdminReviewDeposit } from '@/composables/useAdmin';
import { apiErrorMessage } from '@/lib/api';
import { formatDate, formatRupiah } from '@/lib/utils';
import type { RequestStatus } from '@/types/api';

const page = ref(1);
const status = ref<RequestStatus | ''>('pending');
const query = useAdminDepositRequests(page, status);
const reviewMutation = useAdminReviewDeposit();

const confirmTarget = ref<{ id: number; action: 'approve' | 'reject' } | null>(null);

async function handleConfirm() {
  if (!confirmTarget.value) return;
  try {
    await reviewMutation.mutateAsync(confirmTarget.value);
    toast.success(confirmTarget.value.action === 'approve' ? 'Setoran disetujui.' : 'Setoran ditolak.');
    confirmTarget.value = null;
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Gagal memproses review.'));
  }
}

const requests = computed(() => query.data.value?.deposit_requests ?? []);
</script>

<template>
  <DashboardShell>
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
      <div>
        <h2 class="font-display text-xl font-semibold text-primary-800">Verifikasi Setoran</h2>
        <p class="text-sm text-primary-500 mt-1">Titik kritis bisnis &mdash; saldo bertambah hanya setelah disetujui di sini.</p>
      </div>
      <select v-model="status" class="input !w-auto" @change="page = 1">
        <option value="pending">Menunggu</option>
        <option value="approved">Disetujui</option>
        <option value="rejected">Ditolak</option>
        <option value="">Semua</option>
      </select>
    </div>

    <Skeleton v-if="query.isPending.value" :rows="4" />
    <EmptyState v-else-if="!requests.length" icon="📥" title="Tidak ada permohonan setoran" />

    <div v-else class="card !p-0 overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-cream-100 text-left text-xs uppercase tracking-wide text-primary-500">
          <tr>
            <th class="px-4 py-3">Tanggal</th>
            <th class="px-4 py-3">Rekening</th>
            <th class="px-4 py-3">Nominal</th>
            <th class="px-4 py-3">Metode</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-primary-50">
          <tr v-for="r in requests" :key="r.id">
            <td class="px-4 py-3 text-primary-600">{{ formatDate(r.created_at) }}</td>
            <td class="px-4 py-3 text-primary-600">#{{ r.account_id }}</td>
            <td class="px-4 py-3 font-medium text-primary-800">{{ formatRupiah(r.amount) }}</td>
            <td class="px-4 py-3 text-primary-600">{{ r.payment_method }}</td>
            <td class="px-4 py-3"><StatusBadge :status="r.status" /></td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              <template v-if="r.status === 'pending'">
                <button class="btn-secondary !py-1.5 !px-3 text-xs mr-2" @click="confirmTarget = { id: r.id, action: 'reject' }">Tolak</button>
                <button class="btn-primary !py-1.5 !px-3 text-xs" @click="confirmTarget = { id: r.id, action: 'approve' }">Setujui</button>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
      <div class="px-4 pb-4">
        <Pagination :page="query.data.value?.page ?? 1" :per-page="query.data.value?.per_page ?? 10" :total="query.data.value?.total ?? 0" @update:page="(p) => (page = p)" />
      </div>
    </div>

    <ConfirmModal
      :open="confirmTarget !== null"
      :title="confirmTarget?.action === 'approve' ? 'Setujui Setoran?' : 'Tolak Setoran?'"
      description="Tindakan ini akan langsung tercatat dan tidak bisa dibatalkan."
      :confirm-label="confirmTarget?.action === 'approve' ? 'Setujui' : 'Tolak'"
      :tone="confirmTarget?.action === 'approve' ? 'primary' : 'danger'"
      :loading="reviewMutation.isPending.value"
      @confirm="handleConfirm"
      @cancel="confirmTarget = null"
    />
  </DashboardShell>
</template>
