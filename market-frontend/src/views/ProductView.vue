<script setup lang="ts">
import { computed, ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { toast } from 'vue-sonner';
import MarketShell from '@/components/MarketShell.vue';
import Skeleton from '@/components/Skeleton.vue';
import EmptyState from '@/components/EmptyState.vue';
import { useProduct } from '@/composables/useCatalog';
import { useSetCartQty } from '@/composables/useCart';
import { useSessionStore } from '@/stores/session';
import { apiErrorMessage } from '@/lib/api';
import { formatRupiah } from '@/lib/format';
import { redirectToLogin } from '@/lib/sso';

const props = defineProps<{ id: number }>();
const router = useRouter();
const session = useSessionStore();
const productQuery = useProduct(() => props.id);
const setQty = useSetCartQty();

const qty = ref(1);
const product = computed(() => productQuery.data.value);
const isOwn = computed(() => !!product.value && session.me?.store?.id === product.value.store_id);

async function addToCart(goToCart: boolean) {
  if (!session.isLoggedIn) {
    redirectToLogin(window.location.pathname);
    return;
  }
  try {
    await setQty.mutateAsync({ product_id: props.id, qty: qty.value });
    toast.success('Ditambahkan ke keranjang');
    if (goToCart) router.push({ name: 'cart' });
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Gagal menambahkan ke keranjang.'));
  }
}
</script>

<template>
  <MarketShell>
    <Skeleton v-if="productQuery.isPending.value" :rows="4" />
    <EmptyState v-else-if="!product" icon="🔍" title="Produk tidak ditemukan" description="Produk mungkin sudah tidak dijual.">
      <template #action><RouterLink to="/" class="btn-primary">Kembali belanja</RouterLink></template>
    </EmptyState>

    <div v-else class="grid gap-8 md:grid-cols-2">
      <div class="aspect-square overflow-hidden rounded-xl2 border border-primary-100 bg-cream-200">
        <img v-if="product.image_url" :src="product.image_url" :alt="product.name" class="h-full w-full object-cover" referrerpolicy="no-referrer" />
        <div v-else class="flex h-full items-center justify-center text-7xl" aria-hidden="true">🧺</div>
      </div>

      <div>
        <RouterLink :to="{ name: 'store', params: { slug: product.store_slug } }" class="text-sm font-semibold text-primary-600 hover:underline">
          {{ product.store_name }} · {{ product.store_city }}
        </RouterLink>
        <h1 class="mt-2 text-2xl font-bold">{{ product.name }}</h1>

        <div class="mt-4 rounded-xl border border-primary-100 bg-white p-4">
          <template v-if="product.member_price !== null">
            <p class="text-sm text-primary-500">Harga anggota koperasi</p>
            <p class="font-display text-3xl font-bold text-primary-800">{{ formatRupiah(product.member_price) }}</p>
            <p class="text-sm text-primary-400">Harga normal <span class="line-through">{{ formatRupiah(product.price) }}</span></p>
          </template>
          <p v-else class="font-display text-3xl font-bold text-primary-800">{{ formatRupiah(product.price) }}</p>
          <p class="mt-2 text-sm" :class="product.stock > 0 ? 'text-primary-600' : 'text-secondary-700'">
            {{ product.stock > 0 ? `Stok ${product.stock}` : 'Stok habis' }}<span v-if="product.weight_gram"> · {{ product.weight_gram }} gram</span>
          </p>
        </div>

        <div v-if="isOwn" class="mt-4 rounded-lg border border-gold-200 bg-gold-50 px-4 py-3 text-sm text-gold-800">Ini produk toko Anda.</div>
        <div v-else-if="product.stock > 0" class="mt-6 flex flex-wrap items-center gap-3">
          <label class="sr-only" for="qty">Jumlah</label>
          <div class="flex items-center rounded-lg border border-primary-200 bg-white">
            <button class="px-3 py-2 text-lg" :disabled="qty <= 1" aria-label="Kurangi" @click="qty--">−</button>
            <input id="qty" v-model.number="qty" type="number" min="1" :max="product.stock" class="w-14 border-0 text-center text-sm focus:ring-0" />
            <button class="px-3 py-2 text-lg" :disabled="qty >= product.stock" aria-label="Tambah" @click="qty++">+</button>
          </div>
          <button class="btn-secondary" :disabled="setQty.isPending.value" @click="addToCart(false)">+ Keranjang</button>
          <button class="btn-primary" :disabled="setQty.isPending.value" @click="addToCart(true)">Beli Sekarang</button>
        </div>

        <div class="mt-8">
          <h2 class="mb-2 text-base font-semibold">Deskripsi</h2>
          <p class="whitespace-pre-line text-sm text-primary-700">{{ product.description || 'Tidak ada deskripsi.' }}</p>
        </div>
      </div>
    </div>
  </MarketShell>
</template>
