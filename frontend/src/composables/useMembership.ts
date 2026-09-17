import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import api from '@/lib/api';
import type { MembershipStatus, PinStatus } from '@/types/api';

export function useMembership() {
  return useQuery<MembershipStatus>({
    queryKey: ['membership'],
    queryFn: async () => (await api.get<MembershipStatus>('/membership')).data,
  });
}

export function useActivateMembership() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async () => (await api.post<{ message: string; member_since: string }>('/membership/activate')).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['membership'] });
      queryClient.invalidateQueries({ queryKey: ['profile'] });
      queryClient.invalidateQueries({ queryKey: ['savings'] });
    },
  });
}

export function usePinStatus() {
  return useQuery<PinStatus>({
    queryKey: ['security', 'pin'],
    queryFn: async () => (await api.get<PinStatus>('/security/pin')).data,
  });
}

export function useSetPin() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (pin: string) => (await api.put<{ message: string }>('/security/pin', { pin })).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['security', 'pin'] }),
  });
}
