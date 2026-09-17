import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import api from '@/lib/api';
import { useSessionStore } from '@/stores/session';
import type { CartStoreGroup } from '@/types';

export function useCart() {
  return useQuery<CartStoreGroup[]>({
    queryKey: ['cart'],
    queryFn: async () => (await api.get<{ stores: CartStoreGroup[] }>('/cart')).data.stores,
  });
}

export function useSetCartQty() {
  const qc = useQueryClient();
  const session = useSessionStore();
  return useMutation({
    mutationFn: async (p: { product_id: number; qty: number }) =>
      (await api.put<{ cart_count: number }>('/cart/items', p)).data,
    onSuccess: (d) => {
      session.setCartCount(d.cart_count);
      qc.invalidateQueries({ queryKey: ['cart'] });
    },
  });
}

export function useRemoveCartItem() {
  const qc = useQueryClient();
  const session = useSessionStore();
  return useMutation({
    mutationFn: async (productId: number) => (await api.delete<{ cart_count: number }>(`/cart/items/${productId}`)).data,
    onSuccess: (d) => {
      session.setCartCount(d.cart_count);
      qc.invalidateQueries({ queryKey: ['cart'] });
    },
  });
}
