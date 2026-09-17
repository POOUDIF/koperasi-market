<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import MarketShell from '@/components/MarketShell.vue';
import ProductCard from '@/components/ProductCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import Skeleton from '@/components/Skeleton.vue';
import Pagination from '@/components/Pagination.vue';
import { useProducts } from '@/composables/useCatalog';

const route = useRoute();
const router = useRouter();

const q = computed(() => (typeof route.query.q === 'string' ? route.query.q : ''));
const page = ref(Number(route.query.page) || 1);
watch(q, () => (page.value = 1));

const productsQuery = useProducts(() => ({ q: q.value, page: page.value }));

function setPage(p: number) {
  page.value = p;
  router.replace({ query: { ...route.query, page: p > 1 ? String(p) : undefined } });
}
</script>

<template>
  <MarketShell>
    <section v-if="!q" class="mb-8 overflow-hidden rounded-xl2 bg-gradient-to-br from-primary-700 to-primary-800 p-6 text-white sm:p-10">
      <p class="text-sm font-semibold uppercase tracking-wider text-gold-300">Marketplace Jawa Dwipa Cooperative</p>
      <h1 class="mt-2 max-w-2xl font-display text-2xl font-bold !text-white sm:text-4xl">Belanja produk anggota, dukung ekonomi koperasi</h1>
      <p class="mt-3 max-w-xl text-sm text-primary-100/80">
        Bayar dengan saldo simpanan koperasi. Dana ditahan koperasi dan baru diteruskan ke penjual setelah pesanan Anda terima.
      </p>
    </section>

    <div class="mb-4 flex items-end justify-between">
      <h2 class="text-lg font-semibold">{{ q ? `Hasil pencarian "${q}"` : 'Produk Terbaru' }}</h2>
      <p v-if="productsQuery.data.value" class="text-sm text-primary-500">{{ productsQuery.data.value.total }} produk</p>
    </div>

    <Skeleton v-if="productsQuery.isPending.value" :rows="4" />
    <EmptyState
      v-else-if="!productsQuery.data.value?.items.length"
      icon="🧺"
      :title="q ? 'Produk tidak ditemukan' : 'Belum ada produk'"
      :description="q ? 'Coba kata kunci lain.' : 'Anggota koperasi bisa membuka toko dan mulai berjualan.'"
    />
    <template v-else>
      <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        <ProductCard v-for="p in productsQuery.data.value.items" :key="p.id" :product="p" />
      </div>
      <Pagination :page="page" :per-page="productsQuery.data.value.per_page" :total="productsQuery.data.value.total" @update:page="setPage" />
    </template>
  </MarketShell>
</template>
