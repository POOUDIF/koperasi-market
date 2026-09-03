import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import { toValue, type MaybeRefOrGetter } from 'vue';
import api from '@/lib/api';
import type { Financing, FinancingInstallment, FinancingsPage, PayInstallmentResponse } from '@/types/api';

export function useFinancings(page: MaybeRefOrGetter<number> = 1) {
  return useQuery<FinancingsPage>({
    queryKey: ['financing', page],
    queryFn: async () => (await api.get<FinancingsPage>('/financing', { params: { page: toValue(page) } })).data,
  });
}

export interface ApplyFinancingPayload {
  principal_amount: number;
  duration_months: number;
}

export function useApplyFinancing() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: ApplyFinancingPayload) => api.post<Financing>('/financing/apply', payload).then((r) => r.data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['financing'] }),
  });
}

export function useInstallments(financingId: number | null) {
  return useQuery<FinancingInstallment[]>({
    queryKey: ['financing', financingId, 'installments'],
    queryFn: async () =>
      (await api.get<{ installments: FinancingInstallment[] }>(`/financing/${financingId}/installments`)).data
        .installments ?? [],
    enabled: financingId !== null,
  });
}

export function usePayInstallment() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ installmentId, savings_account_id }: { installmentId: number; savings_account_id: number }) =>
      api
        .post<PayInstallmentResponse>(`/financing/installments/${installmentId}/pay`, { savings_account_id })
        .then((r) => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['financing'] });
      queryClient.invalidateQueries({ queryKey: ['savings', 'accounts'] });
    },
  });
}
