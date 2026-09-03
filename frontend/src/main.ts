import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';

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
  onUnauthorized: () => router.push({ name: 'login' }),
  onForbidden: () => router.push({ name: 'login', query: { reason: 'account_disabled' } }),
});

app.mount('#app');
