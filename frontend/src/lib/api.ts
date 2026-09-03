import axios from 'axios';
import Cookies from 'js-cookie';
import { toast } from 'vue-sonner';

export const TOKEN_KEY = 'koperasi_token';

export function getToken(): string | undefined {
  return Cookies.get(TOKEN_KEY);
}

export function saveToken(token: string) {
  Cookies.set(TOKEN_KEY, token, {
    expires: 1, // 1 hari, selaras JWT_TOKEN_TTL_HOURS=24 di backend
    sameSite: 'strict',
    secure: import.meta.env.PROD,
  });
}

export function clearToken() {
  Cookies.remove(TOKEN_KEY);
}

const api = axios.create({
  baseURL: (import.meta.env.VITE_API_BASE_URL || '') + '/api/v1',
  headers: { 'Content-Type': 'application/json' },
  timeout: 10_000,
});

api.interceptors.request.use((config) => {
  const token = getToken();
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Dipasang dari main.ts supaya interceptor bisa redirect via Vue Router
// (bukan window.location.href kasar) tanpa import siklik router <-> api.
let onUnauthorized: (() => void) | null = null;
let onForbidden: (() => void) | null = null;

export function registerAuthHandlers(handlers: { onUnauthorized: () => void; onForbidden: () => void }) {
  onUnauthorized = handlers.onUnauthorized;
  onForbidden = handlers.onForbidden;
}

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (axios.isAxiosError(error)) {
      const status = error.response?.status;
      if (status === 401) {
        clearToken();
        onUnauthorized?.();
      } else if (status === 403) {
        clearToken();
        onForbidden?.();
      } else if (status === 429) {
        toast.error('Terlalu banyak permintaan. Silakan tunggu beberapa saat.');
      } else if (status && status >= 500) {
        toast.error('Terjadi masalah pada server. Coba lagi nanti.');
      } else if (error.code === 'ECONNABORTED' || error.code === 'ERR_NETWORK') {
        toast.error('Tidak dapat terhubung ke server. Pastikan backend berjalan.');
      }
    }
    return Promise.reject(error);
  },
);

export function apiErrorMessage(err: unknown, fallback = 'Terjadi kesalahan.'): string {
  if (axios.isAxiosError(err)) {
    const msg = (err.response?.data as { error?: string } | undefined)?.error;
    if (msg) return msg;
    if (err.code === 'ECONNABORTED' || err.code === 'ERR_NETWORK') {
      return 'Tidak dapat terhubung ke server. Pastikan backend sudah berjalan.';
    }
  }
  return fallback;
}

export default api;
