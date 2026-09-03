import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import { toValue, type MaybeRefOrGetter } from 'vue';
import api from '@/lib/api';
import type {
  DepositRequest,
  DepositRequestsPage,
  SavingsAccount,
  SavingsProduct,
  WithdrawRequest,
  WithdrawRequestsPage,
} from '@/types/api';

export function useSavingsProducts() {
  return useQuery<SavingsProduct[]>({
    queryKey: ['savings', 'products'],
    queryFn: async () => (await api.get<{ products: SavingsProduct[] }>('/savings/products')).data.products ?? [],
    staleTime: 1000 * 60 * 10,
  });
}

export function useSavingsAccounts() {
  return useQuery<SavingsAccount[]>({
    queryKey: ['savings', 'accounts'],
    queryFn: async () => (await api.get<{ accounts: SavingsAccount[] }>('/savings/accounts')).data.accounts ?? [],
  });
}

export function useOpenSavingsAccount() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (savings_product_id: number) =>
      api.post<SavingsAccount>('/savings/accounts', { savings_product_id }).then((r) => r.data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['savings', 'accounts'] }),
  });
}

export interface DepositPayload {
  account_id: number;
  amount: number;
  payment_method: string;
  proof_image_url?: string;
  reference_id?: string;
}

export function useDeposit() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: DepositPayload) => api.post<DepositRequest>('/savings/deposit', payload).then((r) => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['savings', 'deposit-requests'] });
    },
  });
}

export function useDepositRequests(page: MaybeRefOrGetter<number> = 1) {
  return useQuery<DepositRequestsPage>({
    queryKey: ['savings', 'deposit-requests', page],
    queryFn: async () =>
      (await api.get<DepositRequestsPage>('/savings/deposit-requests', { params: { page: toValue(page) } })).data,
  });
}

export interface WithdrawPayload {
  account_id: number;
  amount: number;
  destination_account: string;
  reference_id?: string;
}

export function useWithdraw() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: WithdrawPayload) => api.post<WithdrawRequest>('/savings/withdraw', payload).then((r) => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['savings', 'withdraw-requests'] });
    },
  });
}

export function useWithdrawRequests(page: MaybeRefOrGetter<number> = 1) {
  return useQuery<WithdrawRequestsPage>({
    queryKey: ['savings', 'withdraw-requests', page],
    queryFn: async () =>
      (await api.get<WithdrawRequestsPage>('/savings/withdraw-requests', { params: { page: toValue(page) } })).data,
  });
}
