<script setup lang="ts">
import { RouterLink } from 'vue-router';
import type { Product } from '@/types';
import { formatRupiah } from '@/lib/format';

defineProps<{ product: Product }>();
</script>

<template>
  <RouterLink :to="{ name: 'product', params: { id: product.id } }" class="card group flex flex-col !p-0 overflow-hidden">
    <div class="aspect-square bg-cream-200">
      <img v-if="product.image_url" :src="product.image_url" :alt="product.name" loading="lazy" class="h-full w-full object-cover" referrerpolicy="no-referrer" />
      <div v-else class="flex h-full items-center justify-center text-4xl" aria-hidden="true">🧺</div>
    </div>
    <div class="flex flex-1 flex-col p-3">
      <p class="line-clamp-2 text-sm font-medium text-primary-900 group-hover:text-primary-700">{{ product.name }}</p>
      <div class="mt-auto pt-2">
        <p v-if="product.member_price !== null" class="text-base font-bold text-primary-800">
          {{ formatRupiah(product.member_price) }}
          <span class="badge ml-1 bg-gold-100 !px-1.5 !py-0.5 text-[10px] text-gold-800">Anggota</span>
        </p>
        <p :class="product.member_price !== null ? 'text-xs text-primary-400 line-through' : 'text-base font-bold text-primary-800'">
          {{ formatRupiah(product.price) }}
        </p>
        <p v-if="product.store_name" class="mt-1 truncate text-xs text-primary-500">{{ product.store_name }}<span v-if="product.store_city"> · {{ product.store_city }}</span></p>
      </div>
    </div>
  </RouterLink>
</template>
