import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import api from '@/lib/api';
import type { Me } from '@/types';

/**
 * Status login marketplace. `loaded` membedakan "belum dicek" dari "tamu".
 */
export const useSessionStore = defineStore('session', () => {
  const me = ref<Me | null>(null);
  const loaded = ref(false);

  const isLoggedIn = computed(() => me.value !== null);
  const isAdmin = computed(() => me.value?.role === 'admin');
  const isMember = computed(() => !!me.value?.membership.is_member);

  async function load(force = false) {
    if (loaded.value && !force) return me.value;
    try {
      const { data } = await api.get<Me>('/me', { skipAuthRedirect: true });
      me.value = data;
    } catch {
      me.value = null;
    } finally {
      loaded.value = true;
    }
    return me.value;
  }

  function setCartCount(n: number) {
    if (me.value) me.value.cart_count = n;
  }

  return { me, loaded, isLoggedIn, isAdmin, isMember, load, setCartCount };
});
