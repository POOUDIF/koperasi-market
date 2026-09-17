import { keepPreviousData, useQuery } from '@tanstack/vue-query';
import { computed, toValue, type MaybeRefOrGetter } from 'vue';
import api from '@/lib/api';
import type { Paged, Product, Store } from '@/types';

export function useProducts(params: MaybeRefOrGetter<{ q: string; page: number; store?: number }>) {
  return useQuery<Paged<Product>>({
    queryKey: ['products', computed(() => toValue(params))],
    queryFn: async () => {
      const p = toValue(params);
      const { data } = await api.get<{ products: Product[]; page: number; per_page: number; total: number }>('/products', {
        params: { q: p.q || undefined, page: p.page, store: p.store },
        skipAuthRedirect: true,
      });
      return { items: data.products, page: data.page, per_page: data.per_page, total: data.total };
    },
    placeholderData: keepPreviousData,
  });
}

export function useProduct(id: MaybeRefOrGetter<number>) {
  return useQuery<Product>({
    queryKey: ['product', computed(() => toValue(id))],
    queryFn: async () => (await api.get<Product>(`/products/${toValue(id)}`, { skipAuthRedirect: true })).data,
    retry: false,
  });
}

export function useStorefront(slug: MaybeRefOrGetter<string>) {
  return useQuery<{ store: Store; products: Product[] }>({
    queryKey: ['storefront', computed(() => toValue(slug))],
    queryFn: async () => (await api.get(`/stores/${toValue(slug)}`, { skipAuthRedirect: true })).data,
    retry: false,
  });
}
