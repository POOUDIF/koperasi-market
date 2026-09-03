import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import api, { saveToken } from '@/lib/api';
import type { AuthResponse, RegisterResponse, User } from '@/types/api';
import { useAuthStore } from '@/stores/auth';
import { computed } from 'vue';

export interface RegisterPayload {
  nama_lengkap: string;
  email: string;
  password: string;
}

export interface LoginPayload {
  email: string;
  password: string;
}

export interface VerifyEmailPayload {
  email: string;
  otp: string;
}

/** POST /register — TIDAK mengembalikan token (lihat DOCS/ANALISIS_FRONTEND.md gap #1). */
export function useRegister() {
  return useMutation({
    mutationFn: (payload: RegisterPayload) =>
      api.post<RegisterResponse>('/register', payload).then((r) => r.data),
  });
}

/** POST /verify-email — di sinilah token pertama kali diterbitkan (auto-login). */
export function useVerifyEmail() {
  const authStore = useAuthStore();
  return useMutation({
    mutationFn: (payload: VerifyEmailPayload) =>
      api.post<AuthResponse>('/verify-email', payload).then((r) => r.data),
    onSuccess: (data) => {
      saveToken(data.token);
      authStore.setUser(data.user);
    },
  });
}

export function useResendOtp() {
  return useMutation({
    mutationFn: (email: string) => api.post<{ message: string }>('/resend-otp', { email }).then((r) => r.data),
  });
}

export function useLogin() {
  const authStore = useAuthStore();
  return useMutation({
    mutationFn: (payload: LoginPayload) => api.post<AuthResponse>('/login', payload).then((r) => r.data),
    onSuccess: (data) => {
      saveToken(data.token);
      authStore.setUser(data.user);
    },
  });
}

export function useProfile() {
  const authStore = useAuthStore();
  const query = useQuery<User>({
    queryKey: ['profile'],
    queryFn: async () => {
      const res = await api.get<User>('/profile');
      authStore.setUser(res.data);
      return res.data;
    },
    enabled: computed(() => authStore.isAuthenticated),
    retry: false,
    staleTime: 1000 * 60 * 5,
  });
  return query;
}

export function useLogout() {
  const authStore = useAuthStore();
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => api.post('/logout'),
    onSettled: () => {
      authStore.reset();
      queryClient.clear();
    },
  });
}
