import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import { computed, toValue, type MaybeRefOrGetter } from 'vue';
import api from '@/lib/api';
import type { Order, Paged } from '@/types';

type OrdersResponse = { orders: Order[]; page: number; per_page: number; total: number };

export function useMyOrders(params: MaybeRefOrGetter<{ status: string; page: number }>) {
  return useQuery<Paged<Order>>({
    queryKey: ['orders', computed(() => toValue(params))],
    queryFn: async () => {
      const p = toValue(params);
      const { data } = await api.get<OrdersResponse>('/orders', { params: { status: p.status || undefined, page: p.page } });
      return { items: data.orders, page: data.page, per_page: data.per_page, total: data.total };
    },
  });
}

export function useOrder(id: MaybeRefOrGetter<number>) {
  return useQuery<Order>({
    queryKey: ['order', computed(() => toValue(id))],
    queryFn: async () => (await api.get<Order>(`/orders/${toValue(id)}`)).data,
    retry: false,
  });
}

export interface CheckoutPayload {
  store_id: number;
  recipient_name: string;
  recipient_phone: string;
  shipping_address: string;
  buyer_note: string;
}

export function useCheckout() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (p: CheckoutPayload) =>
      (await api.post<{ order: Order; payment_url: string | null }>('/checkout', p)).data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['cart'] });
      qc.invalidateQueries({ queryKey: ['orders'] });
    },
  });
}

export function usePayOrder() {
  return useMutation({
    mutationFn: async (id: number) => (await api.post<{ payment_url: string }>(`/orders/${id}/pay`)).data,
  });
}

function useOrderAction(action: 'cancel' | 'complete') {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (p: { id: number; reason?: string }) =>
      (await api.post<Order>(`/orders/${p.id}/${action}`, p.reason ? { reason: p.reason } : {})).data,
    onSuccess: (o) => {
      qc.setQueryData(['order', o.id], o);
      qc.invalidateQueries({ queryKey: ['orders'] });
    },
  });
}

export const useCancelOrder = () => useOrderAction('cancel');
export const useCompleteOrder = () => useOrderAction('complete');
