<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { toast } from 'vue-sonner';
import { useQueryClient } from '@tanstack/vue-query';
import DashboardShell from '@/components/DashboardShell.vue';
import Skeleton from '@/components/Skeleton.vue';
import { useActivateMembership, useMembership } from '@/composables/useMembership';
import { apiErrorMessage } from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useAuthStore } from '@/stores/auth';
import api from '@/lib/api';
import type { User } from '@/types/api';

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
const queryClient = useQueryClient();
const membershipQuery = useMembership();
const activateMutation = useActivateMembership();

const m = computed(() => membershipQuery.data.value);

async function activate() {
  try {
    await activateMutation.mutateAsync();
    // Segarkan user di store supaya guard router langsung mengizinkan modul anggota.
    const { data } = await api.get<User>('/profile');
    authStore.setUser(data);
    await queryClient.invalidateQueries();
    toast.success('Selamat! Keanggotaan koperasi Anda aktif.');
    const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : '/dashboard';
    router.push(redirect);
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Aktivasi keanggotaan gagal.'));
  }
}
</script>

<template>
  <DashboardShell>
    <div class="max-w-2xl">
      <Skeleton v-if="membershipQuery.isPending.value" :rows="4" />

      <div v-else-if="m?.is_member" class="card">
        <p class="text-sm font-semibold uppercase tracking-wider text-primary-600">Anggota Aktif</p>
        <h2 class="mt-1 text-2xl font-bold">Keanggotaan koperasi Anda aktif</h2>
        <p class="mt-2 text-sm text-primary-600">Anggota sejak {{ formatDate(m.member_since) }}.</p>
        <RouterLink to="/dashboard" class="btn-primary mt-6">Ke Dashboard</RouterLink>
      </div>

      <div v-else class="card">
        <p class="text-sm font-semibold uppercase tracking-wider text-gold-700">Satu langkah lagi</p>
        <h2 class="mt-1 text-2xl font-bold">Aktifkan Keanggotaan Koperasi</h2>
        <p class="mt-2 text-sm text-primary-600">
          Akun JDC Anda sudah aktif. Untuk menabung, mengajukan pembiayaan, membeli emas digital, dan membayar
          di Marketplace dengan saldo koperasi, aktifkan keanggotaan koperasi syariah Jawa Dwipa.
        </p>

        <ol class="mt-6 space-y-4">
          <li class="flex items-start gap-3">
            <span
              class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm font-bold"
              :class="m?.kyc_completed ? 'bg-primary-700 text-white' : 'bg-gold-100 text-gold-800'"
            >{{ m?.kyc_completed ? '✓' : '1' }}</span>
            <div>
              <p class="font-semibold text-primary-800">Lengkapi profil KYC</p>
              <p class="text-sm text-primary-500">NIK, alamat, pekerjaan, dan kontak darurat sesuai KTP.</p>
              <RouterLink v-if="!m?.kyc_completed" to="/dashboard/kyc" class="mt-2 inline-block text-sm font-semibold text-primary-700 hover:underline">
                Isi profil KYC &rarr;
              </RouterLink>
            </div>
          </li>
          <li class="flex items-start gap-3">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gold-100 text-sm font-bold text-gold-800">2</span>
            <div>
              <p class="font-semibold text-primary-800">Aktivasi keanggotaan</p>
              <p class="text-sm text-primary-500">
                Rekening Simpanan Pokok dan Simpanan Wajib dibuka otomatis. Setoran awal dilakukan melalui menu Top-up.
              </p>
            </div>
          </li>
        </ol>

        <button class="btn-primary mt-8 w-full sm:w-auto" :disabled="!m?.kyc_completed || activateMutation.isPending.value" @click="activate">
          {{ activateMutation.isPending.value ? 'Memproses...' : 'Aktifkan Keanggotaan' }}
        </button>
      </div>
    </div>
  </DashboardShell>
</template>
