import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import { computed, toValue, type MaybeRefOrGetter } from 'vue';
import api from '@/lib/api';
import { useSessionStore } from '@/stores/session';
import type { Order, Paged, Product, ProductInput, Store } from '@/types';

export function useMyStore() {
  return useQuery<Store | null>({
    queryKey: ['seller', 'store'],
    queryFn: async () => (await api.get<{ store: Store | null }>('/seller/store')).data.store,
  });
}

export function useSaveStore(isNew: MaybeRefOrGetter<boolean>) {
  const qc = useQueryClient();
  const session = useSessionStore();
  return useMutation({
    mutationFn: async (p: { name: string; description: string; city: string }) =>
      toValue(isNew)
        ? (await api.post<{ store: Store }>('/seller/store', p)).data.store
        : (await api.put<{ store: Store }>('/seller/store', p)).data.store,
    onSuccess: (store) => {
      qc.setQueryData(['seller', 'store'], store);
      session.load(true);
    },
  });
}

export function useSellerProducts() {
  return useQuery<Product[]>({
    queryKey: ['seller', 'products'],
    queryFn: async () => (await api.get<{ products: Product[] }>('/seller/products')).data.products,
  });
}

export function useSaveProduct() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (p: { id?: number; input: ProductInput }) =>
      p.id
        ? (await api.put<Product>(`/seller/products/${p.id}`, p.input)).data
        : (await api.post<Product>('/seller/products', p.input)).data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['seller', 'products'] });
      qc.invalidateQueries({ queryKey: ['products'] });
    },
  });
}

export function useSellerOrders(params: MaybeRefOrGetter<{ status: string; page: number }>) {
  return useQuery<Paged<Order>>({
    queryKey: ['seller', 'orders', computed(() => toValue(params))],
    queryFn: async () => {
      const p = toValue(params);
      const { data } = await api.get<{ orders: Order[]; page: number; per_page: number; total: number }>('/seller/orders', {
        params: { status: p.status || undefined, page: p.page },
      });
      return { items: data.orders, page: data.page, per_page: data.per_page, total: data.total };
    },
  });
}

export function useShipOrder() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (p: { id: number; tracking_number: string }) =>
      (await api.post<Order>(`/seller/orders/${p.id}/ship`, { tracking_number: p.tracking_number })).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['seller', 'orders'] }),
  });
}

export function useSellerCancelOrder() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (p: { id: number; reason: string }) =>
      (await api.post<Order>(`/seller/orders/${p.id}/cancel`, { reason: p.reason })).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['seller', 'orders'] }),
  });
}
