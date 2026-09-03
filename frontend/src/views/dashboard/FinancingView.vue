<script setup lang="ts">
import { ref, reactive, computed } from 'vue';
import { toast } from 'vue-sonner';
import { useRouter } from 'vue-router';
import DashboardShell from '@/components/DashboardShell.vue';
import Skeleton from '@/components/Skeleton.vue';
import EmptyState from '@/components/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import FormModal from '@/components/FormModal.vue';
import Pagination from '@/components/Pagination.vue';
import { useApplyFinancing, useFinancings } from '@/composables/useFinancing';
import { apiErrorMessage } from '@/lib/api';
import { formatDate, formatRupiah } from '@/lib/utils';

const router = useRouter();
const page = ref(1);
const financingsQuery = useFinancings(page);
const applyMutation = useApplyFinancing();

const applyModal = ref(false);
const form = reactive({ principal_amount: 0, duration_months: 12 });

async function handleApply() {
  try {
    await applyMutation.mutateAsync({ ...form });
    toast.success('Pengajuan pembiayaan berhasil dikirim, menunggu review admin.');
    applyModal.value = false;
    form.principal_amount = 0;
    form.duration_months = 12;
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Gagal mengajukan pembiayaan.'));
  }
}

const financings = computed(() => financingsQuery.data.value?.financings ?? []);

function canViewInstallments(status: string) {
  return status === 'approved' || status === 'paid';
}
</script>

<template>
  <DashboardShell>
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
      <div>
        <h2 class="font-display text-xl font-semibold text-primary-800">Pembiayaan Murabahah</h2>
        <p class="text-sm text-primary-500 mt-1">Ajukan dan pantau pembiayaan Anda.</p>
      </div>
      <button class="btn-primary" @click="applyModal = true">+ Ajukan Pembiayaan</button>
    </div>

    <Skeleton v-if="financingsQuery.isPending.value" :rows="3" />
    <EmptyState v-else-if="!financings.length" icon="📋" title="Belum ada pengajuan pembiayaan" description="Ajukan pembiayaan Murabahah pertama Anda.">
      <template #action>
        <button class="btn-primary" @click="applyModal = true">Ajukan Pembiayaan</button>
      </template>
    </EmptyState>

    <div v-else class="space-y-4">
      <div v-for="f in financings" :key="f.id" class="card">
        <div class="flex items-start justify-between flex-wrap gap-3">
          <div>
            <p class="text-xs font-mono text-primary-400">{{ f.financing_number }}</p>
            <p class="font-display text-lg font-semibold text-primary-800 mt-0.5">{{ formatRupiah(f.principal_amount) }}</p>
            <p class="text-xs text-primary-500 mt-1">{{ f.duration_months }} bulan &middot; diajukan {{ formatDate(f.created_at) }}</p>
          </div>
          <StatusBadge :status="f.status" />
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mt-4 pt-4 border-t border-primary-50 text-sm">
          <div>
            <p class="text-xs text-primary-400">Margin</p>
            <p class="font-medium text-primary-700">{{ formatRupiah(f.margin_amount) }}</p>
          </div>
          <div>
            <p class="text-xs text-primary-400">Total Dibayar</p>
            <p class="font-medium text-primary-700">{{ formatRupiah(f.total_payable) }}</p>
          </div>
          <div class="col-span-2 sm:col-span-1 flex items-end sm:justify-end">
            <button
              v-if="canViewInstallments(f.status)"
              class="btn-secondary !py-1.5 !px-3 text-xs"
              @click="router.push({ name: 'financing-installments', params: { id: f.id } })"
            >
              Lihat Cicilan
            </button>
          </div>
        </div>
      </div>

      <Pagination
        :page="financingsQuery.data.value?.page ?? 1"
        :per-page="financingsQuery.data.value?.per_page ?? 10"
        :total="financingsQuery.data.value?.total ?? 0"
        @update:page="(p) => (page = p)"
      />
    </div>

    <FormModal :open="applyModal" title="Ajukan Pembiayaan Murabahah" description="Margin dihitung otomatis oleh sistem sesuai kebijakan koperasi saat pengajuan disetujui." @close="applyModal = false">
      <form class="space-y-4" @submit.prevent="handleApply">
        <div>
          <label class="label">Nominal Pokok (Rp)</label>
          <input v-model.number="form.principal_amount" type="number" min="1" required class="input" />
        </div>
        <div>
          <label class="label">Tenor (bulan)</label>
          <input v-model.number="form.duration_months" type="number" min="1" max="360" required class="input" />
        </div>
        <button type="submit" class="btn-primary w-full" :disabled="applyMutation.isPending.value">
          {{ applyMutation.isPending.value ? 'Mengirim...' : 'Ajukan Pembiayaan' }}
        </button>
      </form>
    </FormModal>
  </DashboardShell>
</template>
