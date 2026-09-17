/**
 * Navigasi alur SSO. Login selalu dimulai di BACKEND koperasi
 * (/koperasi/api/v1/sso/login), yang membuat state/nonce/PKCE lalu
 * mengarahkan browser ke JDC Account. SPA tidak pernah menyentuh token.
 */

const API = `${import.meta.env.BASE_URL}api/v1`;

let redirecting = false;

export function ssoLoginUrl(returnTo: string, prompt?: 'login' | 'none'): string {
  const q = new URLSearchParams({ return_to: returnTo });
  if (prompt) q.set('prompt', prompt);
  return `${API}/sso/login?${q.toString()}`;
}

/** Redirect penuh ke login SSO; dipanggil sekali walau banyak request 401 bersamaan. */
export function redirectToLogin(returnTo: string, prompt?: 'login' | 'none') {
  if (redirecting) return;
  redirecting = true;
  window.location.assign(ssoLoginUrl(returnTo, prompt));
}

export const ACCOUNT_URL = '/account';
