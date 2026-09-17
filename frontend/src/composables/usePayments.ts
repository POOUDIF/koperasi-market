import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import { computed, toValue, type MaybeRefOrGetter } from 'vue';
import api from '@/lib/api';
import type { PaymentIntentView, PaymentActionResult } from '@/types/api';

export function usePaymentIntent(id: MaybeRefOrGetter<string>) {
  return useQuery<PaymentIntentView>({
    queryKey: ['payment', computed(() => toValue(id))],
    queryFn: async () => (await api.get<PaymentIntentView>(`/payments/${toValue(id)}`)).data,
    retry: false,
  });
}

export function useConfirmPayment(id: MaybeRefOrGetter<string>) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (payload: { account_id: number; pin: string }) =>
      (await api.post<PaymentActionResult>(`/payments/${toValue(id)}/confirm`, payload)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['savings'] });
      queryClient.invalidateQueries({ queryKey: ['payment'] });
    },
  });
}

export function useCancelPayment(id: MaybeRefOrGetter<string>) {
  return useMutation({
    mutationFn: async () => (await api.post<PaymentActionResult>(`/payments/${toValue(id)}/cancel`)).data,
  });
}
