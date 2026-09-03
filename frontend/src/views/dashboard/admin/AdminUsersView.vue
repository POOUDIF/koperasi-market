<script setup lang="ts">
import { ref, computed } from 'vue';
import DashboardShell from '@/components/DashboardShell.vue';
import Skeleton from '@/components/Skeleton.vue';
import EmptyState from '@/components/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import Pagination from '@/components/Pagination.vue';
import { useAdminUsers } from '@/composables/useAdmin';
import { formatDate, roleLabel } from '@/lib/utils';

const page = ref(1);
const query = useAdminUsers(page);
const users = computed(() => query.data.value?.users ?? []);
</script>

<template>
  <DashboardShell>
    <div class="mb-6">
      <h2 class="font-display text-xl font-semibold text-primary-800">Manajemen Anggota</h2>
      <p class="text-sm text-primary-500 mt-1">Daftar seluruh pengguna terdaftar di koperasi.</p>
    </div>

    <Skeleton v-if="query.isPending.value" :rows="5" />
    <EmptyState v-else-if="!users.length" icon="👥" title="Belum ada anggota" />

    <div v-else class="card !p-0 overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-cream-100 text-left text-xs uppercase tracking-wide text-primary-500">
          <tr>
            <th class="px-4 py-3">Nama</th>
            <th class="px-4 py-3">Email</th>
            <th class="px-4 py-3">Peran</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3">Bergabung</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-primary-50">
          <tr v-for="u in users" :key="u.id">
            <td class="px-4 py-3 font-medium text-primary-800">{{ u.nama_lengkap }}</td>
            <td class="px-4 py-3 text-primary-600">{{ u.email }}</td>
            <td class="px-4 py-3">
              <span class="badge bg-secondary-100 text-secondary-800">{{ roleLabel(u.role) }}</span>
            </td>
            <td class="px-4 py-3"><StatusBadge :status="u.status" /></td>
            <td class="px-4 py-3 text-primary-500">{{ formatDate(u.created_at) }}</td>
          </tr>
        </tbody>
      </table>
      <div class="px-4 pb-4">
        <Pagination :page="query.data.value?.page ?? 1" :per-page="query.data.value?.per_page ?? 10" :total="query.data.value?.total ?? 0" @update:page="(p) => (page = p)" />
      </div>
    </div>
  </DashboardShell>
</template>
