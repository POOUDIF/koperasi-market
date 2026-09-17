/**
 * Navigasi SSO marketplace. Login dimulai di backend market
 * (/market/api/v1/sso/login) yang mengarahkan ke JDC Account.
 */
const API = `${import.meta.env.BASE_URL}api/v1`;
const SILENT_KEY = 'jdc_market_silent_sso';

let redirecting = false;

export function ssoLoginUrl(returnTo: string, prompt?: 'login' | 'none'): string {
  const q = new URLSearchParams({ return_to: returnTo });
  if (prompt) q.set('prompt', prompt);
  return `${API}/sso/login?${q.toString()}`;
}

export function redirectToLogin(returnTo: string, prompt?: 'login' | 'none') {
  if (redirecting) return;
  redirecting = true;
  window.location.assign(ssoLoginUrl(returnTo, prompt));
}

/**
 * SSO senyap untuk halaman publik: bila pengunjung sudah masuk di layanan JDC
 * lain, coba sekali per sesi browser dengan prompt=none — IdP langsung
 * mengembalikan tanpa form login; bila belum masuk, kembali sebagai tamu.
 * @returns TRUE bila redirect dimulai
 */
export function trySilentLogin(returnTo: string): boolean {
  try {
    if (sessionStorage.getItem(SILENT_KEY)) return false;
    sessionStorage.setItem(SILENT_KEY, '1');
  } catch {
    return false; // storage diblokir: jangan berisiko loop redirect
  }
  redirectToLogin(returnTo, 'none');
  return true;
}

export function resetSilentLogin() {
  try {
    sessionStorage.removeItem(SILENT_KEY);
  } catch {
    /* abaikan */
  }
}
