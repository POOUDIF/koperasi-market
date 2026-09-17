import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { toast } from 'vue-sonner';

import App from './App.vue';
import router from './router';
import { registerAuthHandlers } from './lib/api';
import './style.css';

const app = createApp(App);

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 1000 * 60 * 2,
      retry: 1,
      refetchOnWindowFocus: false,
    },
  },
});

app.use(createPinia());
app.use(router);
app.use(VueQueryPlugin, { queryClient });

registerAuthHandlers({
  onForbidden: (code) => {
    if (code === 'MEMBERSHIP_REQUIRED') {
      router.push({ name: 'membership', query: { redirect: router.currentRoute.value.fullPath } });
    } else if (code === 'ACCOUNT_SUSPENDED') {
      router.push({ name: 'auth-error', query: { code: 'account_suspended' } });
    } else {
      toast.error('Akses ditolak.');
    }
  },
});

app.mount('#app');
