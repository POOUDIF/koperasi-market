<script setup lang="ts">
import { RouterLink } from 'vue-router';
import { toast } from 'vue-sonner';
import MarketShell from '@/components/MarketShell.vue';
import EmptyState from '@/components/EmptyState.vue';
import Skeleton from '@/components/Skeleton.vue';
import { useCart, useRemoveCartItem, useSetCartQty } from '@/composables/useCart';
import { apiErrorMessage } from '@/lib/api';
import { formatRupiah, memberUnitPrice } from '@/lib/format';

const cartQuery = useCart();
const setQty = useSetCartQty();
const removeItem = useRemoveCartItem();

async function change(productId: number, qty: number) {
  try {
    await setQty.mutateAsync({ product_id: productId, qty });
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Gagal memperbarui jumlah.'));
  }
}
</script>

<template>
  <MarketShell>
    <h1 class="mb-6 text-2xl font-bold">Keranjang</h1>
    <Skeleton v-if="cartQuery.isPending.value" :rows="3" />
    <EmptyState v-else-if="!cartQuery.data.value?.length" icon="🛒" title="Keranjang kosong" description="Temukan produk anggota koperasi dan tambahkan ke keranjang.">
      <template #action><RouterLink to="/" class="btn-primary">Mulai belanja</RouterLink></template>
    </EmptyState>

    <div v-else class="space-y-6">
      <p class="text-sm text-primary-500">Checkout dilakukan per toko — setiap toko dibayar dan dikirim terpisah.</p>
      <section v-for="g in cartQuery.data.value" :key="g.store_id" class="card">
        <RouterLink :to="{ name: 'store', params: { slug: g.store_slug } }" class="font-semibold text-primary-800 hover:underline">🏪 {{ g.store_name }}</RouterLink>
        <ul class="mt-4 divide-y divide-primary-50">
          <li v-for="it in g.items" :key="it.product_id" class="flex flex-wrap items-center gap-4 py-3">
            <div class="h-16 w-16 shrink-0 overflow-hidden rounded-lg bg-cream-200">
              <img v-if="it.image_url" :src="it.image_url" :alt="it.name" class="h-full w-full object-cover" referrerpolicy="no-referrer" />
            </div>
            <div class="min-w-0 flex-1">
              <RouterLink :to="{ name: 'product', params: { id: it.product_id } }" class="block truncate text-sm font-medium hover:underline">{{ it.name }}</RouterLink>
              <p class="text-sm text-primary-700">{{ formatRupiah(memberUnitPrice(it)) }}</p>
              <p v-if="!it.available" class="text-xs text-secondary-700">Tidak tersedia lagi</p>
              <p v-else-if="it.qty > it.stock" class="text-xs text-secondary-700">Stok tersisa {{ it.stock }}</p>
            </div>
            <div class="flex items-center rounded-lg border border-primary-200">
              <button class="px-2.5 py-1" :disabled="setQty.isPending.value || it.qty <= 1" aria-label="Kurangi" @click="change(it.product_id, it.qty - 1)">−</button>
              <span class="w-8 text-center text-sm">{{ it.qty }}</span>
              <button class="px-2.5 py-1" :disabled="setQty.isPending.value || it.qty >= it.stock" aria-label="Tambah" @click="change(it.product_id, it.qty + 1)">+</button>
            </div>
            <button class="text-sm text-secondary-700 hover:underline" @click="removeItem.mutate(it.product_id)">Hapus</button>
          </li>
        </ul>
        <div class="mt-4 flex items-center justify-between border-t border-primary-100 pt-4">
          <p class="text-sm">Subtotal <span class="font-display text-lg font-bold text-primary-800">{{ formatRupiah(g.subtotal) }}</span></p>
          <RouterLink :to="{ name: 'checkout', params: { storeId: g.store_id } }" class="btn-primary">Checkout Toko Ini</RouterLink>
        </div>
      </section>
    </div>
  </MarketShell>
</template>
