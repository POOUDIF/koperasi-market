<script setup lang="ts">
import { ref } from 'vue';
import DashboardShell from '@/components/DashboardShell.vue';
import Skeleton from '@/components/Skeleton.vue';
import EmptyState from '@/components/EmptyState.vue';
import Pagination from '@/components/Pagination.vue';
import { useMarkAllNotificationsRead, useMarkNotificationRead, useNotifications } from '@/composables/useNotifications';
import { categoryIcon, formatDate } from '@/lib/utils';
import type { AppNotification } from '@/types/api';

const page = ref(1);
const notificationsQuery = useNotifications(page);
const markReadMutation = useMarkNotificationRead();
const markAllReadMutation = useMarkAllNotificationsRead();

function openNotification(n: AppNotification) {
  if (!n.is_read) {
    markReadMutation.mutate(n.id);
  }
}
</script>

<template>
  <DashboardShell>
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
      <div>
        <h2 class="font-display text-xl font-semibold text-primary-800">Notifikasi</h2>
        <p class="text-sm text-primary-500 mt-1">
          <span v-if="notificationsQuery.data.value?.unread_count">
            {{ notificationsQuery.data.value.unread_count }} notifikasi belum dibaca.
          </span>
          <span v-else>Semua notifikasi sudah dibaca.</span>
        </p>
      </div>
      <button
        class="btn-secondary"
        :disabled="!notificationsQuery.data.value?.unread_count || markAllReadMutation.isPending.value"
        @click="markAllReadMutation.mutate()"
      >
        Tandai Semua Dibaca
      </button>
    </div>

    <Skeleton v-if="notificationsQuery.isPending.value" :rows="4" />
    <EmptyState
      v-else-if="!notificationsQuery.data.value?.notifications.length"
      icon="🔔"
      title="Belum ada notifikasi"
      description="Notifikasi terkait setoran, penarikan, dan pembiayaan Anda akan muncul di sini."
    />
    <div v-else class="card !p-0 overflow-hidden">
      <div class="divide-y divide-primary-50">
        <button
          v-for="n in notificationsQuery.data.value.notifications"
          :key="n.id"
          class="flex w-full items-start gap-4 px-5 py-4 text-left transition hover:bg-cream-50"
          :class="{ 'bg-primary-50/50': !n.is_read }"
          @click="openNotification(n)"
        >
          <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-100 text-lg">
            {{ categoryIcon(n.category) }}
          </div>
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
              <p class="font-semibold text-primary-800" :class="{ 'font-bold': !n.is_read }">{{ n.title }}</p>
              <span v-if="!n.is_read" class="h-2 w-2 shrink-0 rounded-full bg-gold-500" aria-label="Belum dibaca" />
            </div>
            <p class="text-sm text-primary-600 mt-0.5">{{ n.message }}</p>
            <p class="text-xs text-primary-400 mt-1.5">{{ formatDate(n.created_at) }}</p>
          </div>
        </button>
      </div>
      <div class="px-5 pb-4">
        <Pagination
          :page="notificationsQuery.data.value.page"
          :per-page="notificationsQuery.data.value.per_page"
          :total="notificationsQuery.data.value.total"
          @update:page="(p) => (page = p)"
        />
      </div>
    </div>
  </DashboardShell>
</template>
