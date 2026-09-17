import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import { computed, toValue, type MaybeRefOrGetter } from 'vue';
import api from '@/lib/api';
import type { Order, Paged, Store } from '@/types';

export function useAdminStores(page: MaybeRefOrGetter<number>) {
  return useQuery<Paged<Store>>({
    queryKey: ['admin', 'stores', computed(() => toValue(page))],
    queryFn: async () => {
      const { data } = await api.get<{ stores: Store[]; page: number; per_page: number; total: number }>('/admin/stores', {
        params: { page: toValue(page) },
      });
      return { items: data.stores, page: data.page, per_page: data.per_page, total: data.total };
    },
  });
}

export function useSetStoreStatus() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (p: { id: number; status: 'active' | 'suspended' }) =>
      (await api.put(`/admin/stores/${p.id}/status`, { status: p.status })).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'stores'] }),
  });
}

export function useAdminOrders(params: MaybeRefOrGetter<{ status: string; page: number }>) {
  return useQuery<Paged<Order>>({
    queryKey: ['admin', 'orders', computed(() => toValue(params))],
    queryFn: async () => {
      const p = toValue(params);
      const { data } = await api.get<{ orders: Order[]; page: number; per_page: number; total: number }>('/admin/orders', {
        params: { status: p.status || undefined, page: p.page },
      });
      return { items: data.orders, page: data.page, per_page: data.per_page, total: data.total };
    },
  });
}
