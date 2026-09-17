import axios from 'axios';
import { toast } from 'vue-sonner';
import { redirectToLogin } from '@/lib/sso';

/**
 * Klien API Marketplace. Sesi = cookie HttpOnly milik backend (pola BFF) —
 * JavaScript tidak pernah memegang token.
 */
declare module 'axios' {
  interface AxiosRequestConfig {
    /** 401 ditangani pemanggil (mis. katalog publik untuk tamu). */
    skipAuthRedirect?: boolean;
  }
}

const api = axios.create({
  baseURL: `${import.meta.env.BASE_URL}api/v1`,
  headers: {
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
  withCredentials: true,
  timeout: 20_000,
});

api.interceptors.response.use(
  (r) => r,
  (error) => {
    if (axios.isAxiosError(error)) {
      const status = error.response?.status;
      if (status === 401 && !error.config?.skipAuthRedirect) {
        redirectToLogin(window.location.pathname + window.location.search);
      } else if (status === 429) {
        toast.error('Terlalu banyak permintaan. Silakan tunggu sebentar.');
      } else if (status && status >= 500) {
        toast.error(apiErrorMessage(error, 'Terjadi masalah pada server. Coba lagi nanti.'));
      } else if (error.code === 'ECONNABORTED' || error.code === 'ERR_NETWORK') {
        toast.error('Tidak dapat terhubung ke server. Periksa koneksi Anda.');
      }
    }
    return Promise.reject(error);
  },
);

export function apiErrorCode(err: unknown): string | undefined {
  return axios.isAxiosError(err) ? (err.response?.data as { code?: string } | undefined)?.code : undefined;
}

export function apiErrorMessage(err: unknown, fallback = 'Terjadi kesalahan.'): string {
  if (axios.isAxiosError(err)) {
    const msg = (err.response?.data as { error?: string } | undefined)?.error;
    if (msg) return msg.charAt(0).toUpperCase() + msg.slice(1);
  }
  return fallback;
}

export default api;
