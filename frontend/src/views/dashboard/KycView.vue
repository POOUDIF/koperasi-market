<script setup lang="ts">
import { reactive, watch, computed } from 'vue';
import { toast } from 'vue-sonner';
import DashboardShell from '@/components/DashboardShell.vue';
import Skeleton from '@/components/Skeleton.vue';
import { useKyc, useUpdateKyc } from '@/composables/useKyc';
import { apiErrorMessage } from '@/lib/api';

const kycQuery = useKyc();
const updateMutation = useUpdateKyc();

const form = reactive({
  nik: '',
  phone_number: '',
  address: '',
  job_title: '',
  monthly_income: 0,
  emergency_contact_name: '',
  emergency_contact_phone: '',
});

watch(
  () => kycQuery.data.value,
  (data) => {
    if (!data) return;
    form.nik = data.nik;
    form.phone_number = data.phone_number;
    form.address = data.address;
    form.job_title = data.job_title;
    form.monthly_income = data.monthly_income;
    form.emergency_contact_name = data.emergency_contact_name;
    form.emergency_contact_phone = data.emergency_contact_phone;
  },
  { immediate: true },
);

const isNew = computed(() => !kycQuery.data.value?.updated_at);

async function handleSubmit() {
  try {
    await updateMutation.mutateAsync({ ...form });
    toast.success('Profil KYC berhasil disimpan.');
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Gagal menyimpan profil KYC.'));
  }
}
</script>

<template>
  <DashboardShell>
    <div class="max-w-2xl">
      <div class="mb-6">
        <h2 class="font-display text-xl font-semibold text-primary-800">Profil KYC</h2>
        <p class="text-sm text-primary-500 mt-1">
          Lengkapi data diri Anda &mdash; dibutuhkan untuk kelancaran proses pengajuan pembiayaan.
        </p>
      </div>

      <Skeleton v-if="kycQuery.isPending.value" :rows="4" />

      <form v-else class="card space-y-5" @submit.prevent="handleSubmit">
        <div v-if="isNew" class="flex items-start gap-2.5 rounded-lg bg-gold-50 border border-gold-200 px-4 py-3 text-sm text-gold-800">
          <span>ℹ️</span><span>Anda belum melengkapi profil KYC. Isi form di bawah untuk melengkapinya.</span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
          <div class="sm:col-span-2">
            <label class="label" for="nik">NIK</label>
            <input id="nik" v-model="form.nik" type="text" inputmode="numeric" maxlength="16" required class="input" placeholder="16 digit sesuai KTP" />
          </div>

          <div>
            <label class="label" for="phone">No. HP</label>
            <input id="phone" v-model="form.phone_number" type="tel" required class="input" placeholder="08xxxxxxxxxx" />
          </div>

          <div>
            <label class="label" for="job">Pekerjaan</label>
            <input id="job" v-model="form.job_title" type="text" required class="input" placeholder="mis. Wiraswasta" />
          </div>

          <div class="sm:col-span-2">
            <label class="label" for="address">Alamat</label>
            <textarea id="address" v-model="form.address" required rows="3" class="input" placeholder="Alamat lengkap sesuai domisili"></textarea>
          </div>

          <div>
            <label class="label" for="income">Penghasilan Bulanan (Rp)</label>
            <input id="income" v-model.number="form.monthly_income" type="number" min="0" required class="input" />
          </div>

          <div>
            <label class="label" for="ec_name">Nama Kontak Darurat</label>
            <input id="ec_name" v-model="form.emergency_contact_name" type="text" required class="input" />
          </div>

          <div class="sm:col-span-2">
            <label class="label" for="ec_phone">No. HP Kontak Darurat</label>
            <input id="ec_phone" v-model="form.emergency_contact_phone" type="tel" required class="input" />
          </div>
        </div>

        <div class="pt-2 flex justify-end">
          <button type="submit" class="btn-primary" :disabled="updateMutation.isPending.value">
            <span v-if="updateMutation.isPending.value" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white" />
            {{ updateMutation.isPending.value ? 'Menyimpan...' : 'Simpan Profil' }}
          </button>
        </div>
      </form>
    </div>
  </DashboardShell>
</template>
