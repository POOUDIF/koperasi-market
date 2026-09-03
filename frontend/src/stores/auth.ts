import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import type { User } from '@/types/api';
import { ADMIN_ROLES } from '@/types/api';
import { getToken, clearToken } from '@/lib/api';

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null);

  const isAuthenticated = computed(() => !!getToken());
  const isAdmin = computed(() => !!user.value && ADMIN_ROLES.includes(user.value.role));

  function setUser(u: User | null) {
    user.value = u;
  }

  function reset() {
    user.value = null;
    clearToken();
  }

  return { user, isAuthenticated, isAdmin, setUser, reset };
});
