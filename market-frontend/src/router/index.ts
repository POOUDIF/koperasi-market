import { createRouter, createWebHistory } from 'vue-router';
import { useSessionStore } from '@/stores/session';
import { redirectToLogin, trySilentLogin } from '@/lib/sso';

declare module 'vue-router' {
  interface RouteMeta {
    requiresAuth?: boolean;
    requiresAdmin?: boolean;
    title?: string;
  }
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior: () => ({ top: 0 }),
  routes: [
    { path: '/', name: 'home', component: () => import('@/views/HomeView.vue'), meta: { title: 'Belanja' } },
    { path: '/products/:id(\\d+)', name: 'product', component: () => import('@/views/ProductView.vue'), props: (r) => ({ id: Number(r.params.id) }) },
    { path: '/stores/:slug', name: 'store', component: () => import('@/views/StoreView.vue'), props: true },
    { path: '/cart', name: 'cart', component: () => import('@/views/CartView.vue'), meta: { requiresAuth: true, title: 'Keranjang' } },
    {
      path: '/checkout/:storeId(\\d+)',
      name: 'checkout',
      component: () => import('@/views/CheckoutView.vue'),
      props: (r) => ({ storeId: Number(r.params.storeId) }),
      meta: { requiresAuth: true, title: 'Checkout' },
    },
    { path: '/orders', name: 'orders', component: () => import('@/views/OrdersView.vue'), meta: { requiresAuth: true, title: 'Pesanan Saya' } },
    {
      path: '/orders/:id(\\d+)',
      name: 'order',
      component: () => import('@/views/OrderDetailView.vue'),
      props: (r) => ({ id: Number(r.params.id) }),
      meta: { requiresAuth: true, title: 'Detail Pesanan' },
    },
    { path: '/seller', name: 'seller', component: () => import('@/views/seller/SellerView.vue'), meta: { requiresAuth: true, title: 'Toko Saya' } },
    { path: '/admin', name: 'admin', component: () => import('@/views/admin/AdminView.vue'), meta: { requiresAuth: true, requiresAdmin: true, title: 'Admin Marketplace' } },
    { path: '/auth/error', name: 'auth-error', component: () => import('@/views/AuthErrorView.vue'), meta: { title: 'Gagal Masuk' } },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/views/NotFoundView.vue'), meta: { title: 'Tidak Ditemukan' } },
  ],
});

router.beforeEach(async (to) => {
  const session = useSessionStore();
  await session.load();

  const target = import.meta.env.BASE_URL.replace(/\/$/, '') + to.fullPath;

  if (!session.isLoggedIn) {
    if (to.meta.requiresAuth) {
      redirectToLogin(target);
      return false;
    }
    // Halaman publik: bila sudah masuk di layanan JDC lain, masuk otomatis tanpa form.
    if (to.name !== 'auth-error' && trySilentLogin(target)) {
      return false;
    }
    return true;
  }

  if (to.meta.requiresAdmin && !session.isAdmin) {
    return { name: 'home' };
  }
  return true;
});

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} — Marketplace JDC` : 'Marketplace JDC';
});

export default router;
