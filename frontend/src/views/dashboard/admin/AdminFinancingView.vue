<script setup lang="ts">
import { ref, computed } from 'vue';
import { toast } from 'vue-sonner';
import DashboardShell from '@/components/DashboardShell.vue';
import Skeleton from '@/components/Skeleton.vue';
import EmptyState from '@/components/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import ConfirmModal from '@/components/ConfirmModal.vue';
import Pagination from '@/components/Pagination.vue';
import { useAdminFinancings, useAdminReviewFinancing } from '@/composables/useAdmin';
import { apiErrorMessage } from '@/lib/api';
import { formatDate, formatRupiah } from '@/lib/utils';

const page = ref(1);
const query = useAdminFinancings(page);
const reviewMutation = useAdminReviewFinancing();

const confirmTarget = ref<{ id: number; action: 'approve' | 'reject' } | null>(null);

async function handleConfirm() {
  if (!confirmTarget.value) return;
  try {
    await reviewMutation.mutateAsync(confirmTarget.value);
    toast.success(confirmTarget.value.action === 'approve' ? 'Pembiayaan disetujui, jadwal cicilan dibuat.' : 'Pembiayaan ditolak.');
    confirmTarget.value = null;
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Gagal memproses review.'));
  }
}

const financings = computed(() => query.data.value?.financings ?? []);
</script>

<template>
  <DashboardShell>
    <div class="mb-6">
      <h2 class="font-display text-xl font-semibold text-primary-800">Review Pembiayaan</h2>
      <p class="text-sm text-primary-500 mt-1">Setujui pengajuan menghasilkan jadwal angsuran otomatis.</p>
    </div>

    <Skeleton v-if="query.isPending.value" :rows="4" />
    <EmptyState v-else-if="!financings.length" icon="📋" title="Belum ada pengajuan pembiayaan" />

    <div v-else class="card !p-0 overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-cream-100 text-left text-xs uppercase tracking-wide text-primary-500">
          <tr>
            <th class="px-4 py-3">No. Akad</th>
            <th class="px-4 py-3">Pokok</th>
            <th class="px-4 py-3">Margin</th>
            <th class="px-4 py-3">Tenor</th>
            <th class="px-4 py-3">Diajukan</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-primary-50">
          <tr v-for="f in financings" :key="f.id">
            <td class="px-4 py-3 font-mono text-xs text-primary-500">{{ f.financing_number }}</td>
            <td class="px-4 py-3 font-medium text-primary-800">{{ formatRupiah(f.principal_amount) }}</td>
            <td class="px-4 py-3 text-primary-600">{{ formatRupiah(f.margin_amount) }}</td>
            <td class="px-4 py-3 text-primary-600">{{ f.duration_months }} bln</td>
            <td class="px-4 py-3 text-primary-600">{{ formatDate(f.created_at) }}</td>
            <td class="px-4 py-3"><StatusBadge :status="f.status" /></td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              <template v-if="f.status === 'pending'">
                <button class="btn-secondary !py-1.5 !px-3 text-xs mr-2" @click="confirmTarget = { id: f.id, action: 'reject' }">Tolak</button>
                <button class="btn-primary !py-1.5 !px-3 text-xs" @click="confirmTarget = { id: f.id, action: 'approve' }">Setujui</button>
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
      :title="confirmTarget?.action === 'approve' ? 'Setujui Pembiayaan?' : 'Tolak Pembiayaan?'"
      description="Tindakan ini akan langsung tercatat dan tidak bisa dibatalkan."
      :confirm-label="confirmTarget?.action === 'approve' ? 'Setujui' : 'Tolak'"
      :tone="confirmTarget?.action === 'approve' ? 'primary' : 'danger'"
      :loading="reviewMutation.isPending.value"
      @confirm="handleConfirm"
      @cancel="confirmTarget = null"
    />
  </DashboardShell>
</template>
