import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import api from '@/lib/api';
import { redirectToLogin } from '@/lib/sso';
import { ADMIN_ROLES, type User } from '@/types/api';

declare module 'vue-router' {
  interface RouteMeta {
    requiresAuth?: boolean;
    /** Hanya untuk akun yang sudah aktivasi keanggotaan koperasi. */
    requiresMember?: boolean;
    requiresAdmin?: boolean;
    title?: string;
  }
}

const member = { requiresAuth: true, requiresMember: true } as const;

const router = createRouter({
  // BASE_URL = '/koperasi/' (vite.config.ts) — routing berbasis path satu domain.
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/', redirect: '/dashboard' },
    // URL lama sebelum SSO: login kini di JDC Account, guard yang mengarahkan.
    { path: '/login', redirect: '/dashboard' },
    { path: '/register', redirect: '/dashboard' },
    { path: '/verify-otp', redirect: '/dashboard' },
    {
      path: '/auth/error',
      name: 'auth-error',
      component: () => import('@/views/auth/AuthErrorView.vue'),
      meta: { title: 'Gagal Masuk' },
    },
    {
      path: '/dashboard',
      name: 'dashboard',
      component: () => import('@/views/dashboard/DashboardHome.vue'),
      meta: { ...member, title: 'Dashboard' },
    },
    {
      path: '/dashboard/membership',
      name: 'membership',
      component: () => import('@/views/dashboard/MembershipView.vue'),
      meta: { requiresAuth: true, title: 'Keanggotaan Koperasi' },
    },
    {
      path: '/dashboard/security',
      name: 'security',
      component: () => import('@/views/dashboard/SecurityView.vue'),
      meta: { requiresAuth: true, title: 'Keamanan Transaksi' },
    },
    {
      path: '/dashboard/kyc',
      name: 'kyc',
      component: () => import('@/views/dashboard/KycView.vue'),
      meta: { requiresAuth: true, title: 'Profil KYC' },
    },
    {
      path: '/dashboard/savings',
      name: 'savings',
      component: () => import('@/views/dashboard/SavingsView.vue'),
      meta: { ...member, title: 'Simpanan' },
    },
    {
      path: '/dashboard/financing',
      name: 'financing',
      component: () => import('@/views/dashboard/FinancingView.vue'),
      meta: { ...member, title: 'Pembiayaan' },
    },
    {
      path: '/dashboard/financing/:id/installments',
      name: 'financing-installments',
      component: () => import('@/views/dashboard/FinancingInstallmentsView.vue'),
      meta: { ...member, title: 'Jadwal Angsuran' },
      props: (route) => ({ id: Number(route.params.id) }),
    },
    {
      path: '/dashboard/gold',
      name: 'gold',
      component: () => import('@/views/dashboard/GoldView.vue'),
      meta: { ...member, title: 'Emas Digital' },
    },
    {
      path: '/dashboard/transactions',
      name: 'transactions-history',
      component: () => import('@/views/dashboard/TransactionsView.vue'),
      meta: { ...member, title: 'Riwayat Transaksi' },
    },
    {
      path: '/dashboard/topup',
      name: 'topup',
      component: () => import('@/views/dashboard/TopUpView.vue'),
      meta: { ...member, title: 'Top-up Saldo' },
    },
    {
      path: '/dashboard/notifications',
      name: 'notifications',
      component: () => import('@/views/dashboard/NotificationsView.vue'),
      meta: { requiresAuth: true, title: 'Notifikasi' },
    },
    {
      path: '/pay/:id',
      name: 'pay',
      component: () => import('@/views/pay/PaymentConfirmView.vue'),
      meta: { ...member, title: 'Konfirmasi Pembayaran' },
      props: true,
    },
    {
      path: '/dashboard/admin',
      name: 'admin-home',
      component: () => import('@/views/dashboard/admin/AdminHome.vue'),
      meta: { requiresAuth: true, requiresAdmin: true, title: 'Panel Admin' },
    },
    {
      path: '/dashboard/admin/deposits',
      name: 'admin-deposits',
      component: () => import('@/views/dashboard/admin/AdminDepositsView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true, title: 'Verifikasi Setoran' },
    },
    {
      path: '/dashboard/admin/withdrawals',
      name: 'admin-withdrawals',
      component: () => import('@/views/dashboard/admin/AdminWithdrawalsView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true, title: 'Verifikasi Penarikan' },
    },
    {
      path: '/dashboard/admin/financing',
      name: 'admin-financing',
      component: () => import('@/views/dashboard/admin/AdminFinancingView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true, title: 'Review Pembiayaan' },
    },
    {
      path: '/dashboard/admin/users',
      name: 'admin-users',
      component: () => import('@/views/dashboard/admin/AdminUsersView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true, title: 'Manajemen Anggota' },
    },
    {
      path: '/dashboard/admin/gold-price',
      name: 'admin-gold-price',
      component: () => import('@/views/dashboard/admin/AdminGoldPriceView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true, title: 'Harga Emas' },
    },
    {
      path: '/dashboard/admin/transactions',
      name: 'admin-transactions',
      component: () => import('@/views/dashboard/admin/AdminTransactionsView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true, title: 'Riwayat Transaksi' },
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/views/NotFoundView.vue'),
    },
  ],
});

/**
 * Guard global. Backend tetap sumber kebenaran otorisasi (401/403 di setiap
 * request) — guard di sini murni UX.
 *
 * Tanpa sesi → redirect penuh ke login SSO (JDC Account), lalu kembali ke
 * halaman yang sama. Akun yang belum aktivasi keanggotaan diarahkan ke
 * halaman aktivasi.
 */
router.beforeEach(async (to) => {
  if (!to.meta.requiresAuth) {
    return true;
  }

  const authStore = useAuthStore();

  if (!authStore.user) {
    try {
      const { data } = await api.get<User>('/profile', { skipAuthRedirect: true });
      authStore.setUser(data);
    } catch {
      authStore.reset();
      const base = import.meta.env.BASE_URL.replace(/\/$/, '');
      redirectToLogin(base + to.fullPath);
      return false;
    }
  }

  if (to.meta.requiresAdmin && !ADMIN_ROLES.includes(authStore.user!.role)) {
    return { name: authStore.isMember ? 'dashboard' : 'membership' };
  }

  if (to.meta.requiresMember && !authStore.isMember) {
    return { name: 'membership', query: { redirect: to.fullPath } };
  }

  return true;
});

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} — Koperasi Digital JDC` : 'Koperasi Digital JDC';
});

export default router;
