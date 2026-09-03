import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import api from '@/lib/api';
import type { GoldHoldingResponse, GoldPrice, GoldTransaction } from '@/types/api';

export function useGoldPrice() {
  return useQuery<GoldPrice>({
    queryKey: ['gold', 'price'],
    queryFn: async () => (await api.get<GoldPrice>('/gold/price')).data,
    staleTime: 1000 * 60 * 15,
    refetchInterval: 1000 * 60 * 15,
    retry: false, // jangan retry pada 503 (harga belum diset admin)
  });
}

export interface GoldTradePayload {
  gram_amount: number;
  savings_account_id: number;
}

export function useBuyGold() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: GoldTradePayload) => api.post<GoldTransaction>('/gold/buy', payload).then((r) => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['gold', 'holding'] });
      queryClient.invalidateQueries({ queryKey: ['savings', 'accounts'] });
    },
  });
}

export function useSellGold() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: GoldTradePayload) => api.post<GoldTransaction>('/gold/sell', payload).then((r) => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['gold', 'holding'] });
      queryClient.invalidateQueries({ queryKey: ['savings', 'accounts'] });
    },
  });
}

/** Polling otomatis 30 detik selama ada transaksi pending/processing (§15, alur async worker). */
export function useGoldHolding() {
  return useQuery<GoldHoldingResponse>({
    queryKey: ['gold', 'holding'],
    queryFn: async () => (await api.get<GoldHoldingResponse>('/gold/holding')).data,
    refetchInterval: (query) => {
      const data = query.state.data;
      const hasPending = data?.transactions.some((t) => t.status === 'pending' || t.status === 'processing');
      return hasPending ? 30_000 : false;
    },
  });
}
