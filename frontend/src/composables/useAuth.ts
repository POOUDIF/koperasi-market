import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import api from '@/lib/api';
import type { User } from '@/types/api';
import { useAuthStore } from '@/stores/auth';

/**
 * Login, registrasi, dan verifikasi email kini di JDC Account (/account).
 * Di sini hanya profil sesi aktif dan logout.
 */

export function useProfile() {
  const authStore = useAuthStore();
  return useQuery<User>({
    queryKey: ['profile'],
    queryFn: async () => {
      const res = await api.get<User>('/profile');
      authStore.setUser(res.data);
      return res.data;
    },
    retry: false,
    staleTime: 1000 * 60 * 5,
  });
}

/**
 * Logout global: backend menghapus sesi koperasi & mencabut refresh token,
 * lalu mengembalikan URL end_session JDC Account yang mengeluarkan pengguna
 * dari semua layanan (§3.4).
 */
export function useLogout() {
  const authStore = useAuthStore();
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async () => (await api.post<{ redirect_url: string }>('/sso/logout')).data,
    onSettled: (data) => {
      authStore.reset();
      queryClient.clear();
      window.location.assign(data?.redirect_url ?? '/');
    },
  });
}
