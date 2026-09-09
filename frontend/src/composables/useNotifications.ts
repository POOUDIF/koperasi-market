import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import { toValue, type MaybeRefOrGetter } from 'vue';
import api from '@/lib/api';
import type { NotificationsPage } from '@/types/api';

export function useNotifications(page: MaybeRefOrGetter<number> = 1) {
  return useQuery<NotificationsPage>({
    queryKey: ['notifications', 'list', page],
    queryFn: async () =>
      (await api.get<NotificationsPage>('/notifications', { params: { page: toValue(page) } })).data,
  });
}

/** Dipakai lonceng notifikasi di header — dipoll berkala, ringan (satu angka). */
export function useUnreadNotificationCount() {
  return useQuery<number>({
    queryKey: ['notifications', 'unread-count'],
    queryFn: async () => (await api.get<{ unread_count: number }>('/notifications/unread-count')).data.unread_count,
    refetchInterval: 30_000,
  });
}

export function useMarkNotificationRead() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => api.put(`/notifications/${id}/read`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['notifications'] });
    },
  });
}

export function useMarkAllNotificationsRead() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => api.put('/notifications/read-all'),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['notifications'] });
    },
  });
}
