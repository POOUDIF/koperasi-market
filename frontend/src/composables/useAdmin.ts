import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import { toValue, type MaybeRefOrGetter } from 'vue';
import api from '@/lib/api';
import type {
  DepositRequestsPage,
  Financing,
  FinancingsPage,
  GoldPrice,
  GoldTxPage,
  RequestStatus,
  SavingsTxPage,
  UsersPage,
  WithdrawRequestsPage,
} from '@/types/api';

type ReviewAction = 'approve' | 'reject';

// --------------------------------------------------------------- financing

export function useAdminReviewFinancing() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, action }: { id: number; action: ReviewAction }) =>
      api.put<{ message: string; financing: Financing }>(`/admin/financing/${id}/review`, { action }).then((r) => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'transactions', 'financing'] });
    },
  });
}

export function useAdminFinancings(page: MaybeRefOrGetter<number> = 1) {
  return useQuery<FinancingsPage>({
    queryKey: ['admin', 'transactions', 'financing', page],
    queryFn: async () =>
      (await api.get<FinancingsPage>('/admin/transactions/financing', { params: { page: toValue(page) } })).data,
  });
}

// ----------------------------------------------------------------- deposit

export function useAdminDepositRequests(page: MaybeRefOrGetter<number> = 1, status: MaybeRefOrGetter<RequestStatus | ''> = 'pending') {
  return useQuery<DepositRequestsPage>({
    queryKey: ['admin', 'deposit-requests', page, status],
    queryFn: async () =>
      (
        await api.get<DepositRequestsPage>('/admin/savings/deposit-requests', {
          params: { page: toValue(page), status: toValue(status) || undefined },
        })
      ).data,
  });
}

export function useAdminReviewDeposit() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, action }: { id: number; action: ReviewAction }) =>
      api.put(`/admin/savings/deposit-requests/${id}/review`, { action }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'deposit-requests'] }),
  });
}

// ---------------------------------------------------------------- withdraw

export function useAdminWithdrawRequests(page: MaybeRefOrGetter<number> = 1, status: MaybeRefOrGetter<RequestStatus | ''> = 'pending') {
  return useQuery<WithdrawRequestsPage>({
    queryKey: ['admin', 'withdraw-requests', page, status],
    queryFn: async () =>
      (
        await api.get<WithdrawRequestsPage>('/admin/savings/withdraw-requests', {
          params: { page: toValue(page), status: toValue(status) || undefined },
        })
      ).data,
  });
}

export function useAdminReviewWithdraw() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, action }: { id: number; action: ReviewAction }) =>
      api.put(`/admin/savings/withdraw-requests/${id}/review`, { action }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'withdraw-requests'] }),
  });
}

// -------------------------------------------------------------------- gold

export function useAdminTxGold(page: MaybeRefOrGetter<number> = 1) {
  return useQuery<GoldTxPage>({
    queryKey: ['admin', 'transactions', 'gold', page],
    queryFn: async () => (await api.get<GoldTxPage>('/admin/transactions/gold', { params: { page: toValue(page) } })).data,
  });
}

export function useAdminSetGoldPrice() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: { buy_price_per_gram: number; sell_price_per_gram: number }) =>
      api.post<{ message: string; price: GoldPrice }>('/admin/gold/price', payload).then((r) => r.data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['gold', 'price'] }),
  });
}

// ------------------------------------------------------------------ saving

export function useAdminTxSaving(page: MaybeRefOrGetter<number> = 1) {
  return useQuery<SavingsTxPage>({
    queryKey: ['admin', 'transactions', 'saving', page],
    queryFn: async () => (await api.get<SavingsTxPage>('/admin/transactions/saving', { params: { page: toValue(page) } })).data,
  });
}

// ------------------------------------------------------------------- users

export function useAdminUsers(page: MaybeRefOrGetter<number> = 1) {
  return useQuery<UsersPage>({
    queryKey: ['admin', 'users', page],
    queryFn: async () => (await api.get<UsersPage>('/admin/users', { params: { page: toValue(page) } })).data,
  });
}
