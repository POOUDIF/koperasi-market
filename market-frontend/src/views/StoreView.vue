<script setup lang="ts">
import MarketShell from '@/components/MarketShell.vue';
import ProductCard from '@/components/ProductCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import Skeleton from '@/components/Skeleton.vue';
import { useStorefront } from '@/composables/useCatalog';
import { formatDate } from '@/lib/format';

const props = defineProps<{ slug: string }>();
const storeQuery = useStorefront(() => props.slug);
</script>

<template>
  <MarketShell>
    <Skeleton v-if="storeQuery.isPending.value" :rows="4" />
    <EmptyState v-else-if="!storeQuery.data.value" icon="🏪" title="Toko tidak ditemukan" />
    <template v-else>
      <section class="card mb-6">
        <p class="text-xs font-semibold uppercase tracking-wider text-gold-700">Toko Anggota Koperasi</p>
        <h1 class="mt-1 text-2xl font-bold">{{ storeQuery.data.value.store.name }}</h1>
        <p class="text-sm text-primary-500">{{ storeQuery.data.value.store.city }} · bergabung {{ formatDate(storeQuery.data.value.store.created_at) }}</p>
        <p v-if="storeQuery.data.value.store.description" class="mt-3 whitespace-pre-line text-sm text-primary-700">{{ storeQuery.data.value.store.description }}</p>
      </section>
      <EmptyState v-if="!storeQuery.data.value.products.length" icon="🧺" title="Belum ada produk" />
      <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        <ProductCard v-for="p in storeQuery.data.value.products" :key="p.id" :product="p" />
      </div>
    </template>
  </MarketShell>
</template>
