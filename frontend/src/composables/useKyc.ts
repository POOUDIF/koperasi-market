import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import api from '@/lib/api';
import type { KycProfile } from '@/types/api';

export function useKyc() {
  return useQuery<KycProfile>({
    queryKey: ['kyc'],
    queryFn: async () => (await api.get<KycProfile>('/profile/kyc')).data,
  });
}

export function useUpdateKyc() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: Omit<KycProfile, 'user_id' | 'created_at' | 'updated_at'>) =>
      api.put<{ message: string; profile: KycProfile }>('/profile/kyc', payload).then((r) => r.data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['kyc'] }),
  });
}
