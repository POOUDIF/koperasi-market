import { useQuery } from '@tanstack/vue-query';
import { toValue, type MaybeRefOrGetter } from 'vue';
import api from '@/lib/api';
import type { TransactionHistoryPage, TransactionJenis } from '@/types/api';

export interface TransactionHistoryParams {
  page: number;
  jenis: TransactionJenis;
  date: string;
}

export function useTransactionHistory(params: MaybeRefOrGetter<TransactionHistoryParams>) {
  return useQuery<TransactionHistoryPage>({
    queryKey: ['transactions', 'history', params],
    queryFn: async () => {
      const p = toValue(params);
      return (
        await api.get<TransactionHistoryPage>('/transactions', {
          params: { page: p.page, jenis: p.jenis, date: p.date || undefined },
        })
      ).data;
    },
  });
}
