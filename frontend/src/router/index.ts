import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import api, { getToken } from '@/lib/api';
import { ADMIN_ROLES, type User } from '@/types/api';

declare module 'vue-router' {
  interface RouteMeta {
    requiresAuth?: boolean;
    requiresAdmin?: boolean;
    guestOnly?: boolean;
    title?: string;
  }
}

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      redirect: '/login',
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/auth/LoginView.vue'),
      meta: { guestOnly: true, title: 'Masuk' },
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('@/views/auth/RegisterView.vue'),
      meta: { guestOnly: true, title: 'Daftar' },
    },
    {
      path: '/verify-otp',
      name: 'verify-otp',
      component: () => import('@/views/auth/VerifyOtpView.vue'),
      meta: { guestOnly: true, title: 'Verifikasi Email' },
    },
    {
      path: '/dashboard',
      name: 'dashboard',
      component: () => import('@/views/dashboard/DashboardHome.vue'),
      meta: { requiresAuth: true, title: 'Dashboard' },
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
      meta: { requiresAuth: true, title: 'Simpanan' },
    },
    {
      path: '/dashboard/financing',
      name: 'financing',
      component: () => import('@/views/dashboard/FinancingView.vue'),
      meta: { requiresAuth: true, title: 'Pembiayaan' },
    },
    {
      path: '/dashboard/financing/:id/installments',
      name: 'financing-installments',
      component: () => import('@/views/dashboard/FinancingInstallmentsView.vue'),
      meta: { requiresAuth: true, title: 'Jadwal Angsuran' },
      props: (route) => ({ id: Number(route.params.id) }),
    },
    {
      path: '/dashboard/gold',
      name: 'gold',
      component: () => import('@/views/dashboard/GoldView.vue'),
      meta: { requiresAuth: true, title: 'Emas Digital' },
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
 * request) — guard di sini murni UX: cegah anggota biasa membuka layout admin,
 * dan cegah orang yang sudah login membuka halaman login/register lagi.
 *
 * Diverifikasi lewat GET /profile langsung (bukan cuma percaya state Pinia)
 * supaya hard-refresh / buka-link-langsung ke rute admin tetap tervalidasi.
 */
router.beforeEach(async (to) => {
  const token = getToken();
  const authStore = useAuthStore();

  if (to.meta.guestOnly && token) {
    return { name: 'dashboard' };
  }

  if (!to.meta.requiresAuth) {
    return true;
  }

  if (!token) {
    return { name: 'login' };
  }

  if (!authStore.user) {
    try {
      const { data } = await api.get<User>('/profile');
      authStore.setUser(data);
    } catch {
      authStore.reset();
      return { name: 'login' };
    }
  }

  if (to.meta.requiresAdmin && !ADMIN_ROLES.includes(authStore.user!.role)) {
    return { name: 'dashboard' };
  }

  return true;
});

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} — Jawa Dwipa Cooperative` : 'Jawa Dwipa Cooperative';
});

export default router;
