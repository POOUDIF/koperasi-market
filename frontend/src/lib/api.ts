import axios from 'axios';
import { toast } from 'vue-sonner';
import { redirectToLogin } from '@/lib/sso';

/**
 * Klien API koperasi. Autentikasi memakai cookie sesi HttpOnly yang dipasang
 * backend (pola BFF, DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md §3.1) — tidak ada
 * token yang disimpan atau bisa dibaca JavaScript.
 */
declare module 'axios' {
  interface AxiosRequestConfig {
    /** Jangan redirect otomatis ke login saat 401 (pemanggil menangani sendiri). */
    skipAuthRedirect?: boolean;
  }
}

const api = axios.create({
  baseURL: `${import.meta.env.BASE_URL}api/v1`,
  headers: {
    'Content-Type': 'application/json',
    // Wajib untuk POST/PUT/DELETE: backend menolak request ber-cookie tanpa header ini (CSRF).
    'X-Requested-With': 'XMLHttpRequest',
  },
  withCredentials: true,
  timeout: 15_000,
});

// Dipasang dari main.ts supaya interceptor bisa redirect via Vue Router
// tanpa import siklik router <-> api.
let onForbidden: ((code: string | undefined) => void) | null = null;

export function registerAuthHandlers(handlers: { onForbidden: (code: string | undefined) => void }) {
  onForbidden = handlers.onForbidden;
}

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (axios.isAxiosError(error)) {
      const status = error.response?.status;
      const code = (error.response?.data as { code?: string } | undefined)?.code;

      if (status === 401 && !error.config?.skipAuthRedirect) {
        // Step-up: aksi sensitif butuh kata sandi dimasukkan ulang di JDC Account.
        redirectToLogin(window.location.pathname + window.location.search, code === 'REAUTH_REQUIRED' ? 'login' : undefined);
      } else if (status === 403) {
        onForbidden?.(code);
      } else if (status === 429) {
        toast.error('Terlalu banyak permintaan. Silakan tunggu beberapa saat.');
      } else if (status && status >= 500) {
        toast.error('Terjadi masalah pada server. Coba lagi nanti.');
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
    if (msg) return msg;
    if (err.code === 'ECONNABORTED' || err.code === 'ERR_NETWORK') {
      return 'Tidak dapat terhubung ke server. Periksa koneksi Anda.';
    }
  }
  return fallback;
}

export default api;
