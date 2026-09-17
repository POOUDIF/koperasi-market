import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import type { User } from '@/types/api';
import { ADMIN_ROLES } from '@/types/api';

/**
 * State pengguna di memori. Sumber kebenaran sesi adalah cookie HttpOnly di
 * backend — store ini hanya cache hasil GET /profile.
 */
export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null);

  const isAuthenticated = computed(() => user.value !== null);
  const isAdmin = computed(() => !!user.value && ADMIN_ROLES.includes(user.value.role));
  const isMember = computed(() => !!user.value?.is_member);

  function setUser(u: User | null) {
    user.value = u;
  }

  function reset() {
    user.value = null;
  }

  return { user, isAuthenticated, isAdmin, isMember, setUser, reset };
});
