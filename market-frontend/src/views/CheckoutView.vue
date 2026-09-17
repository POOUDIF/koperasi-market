<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import MarketShell from '@/components/MarketShell.vue';
import Skeleton from '@/components/Skeleton.vue';
import EmptyState from '@/components/EmptyState.vue';
import { useCart } from '@/composables/useCart';
import { useCheckout } from '@/composables/useOrders';
import { useSessionStore } from '@/stores/session';
import { apiErrorCode, apiErrorMessage } from '@/lib/api';
import { formatRupiah, memberUnitPrice } from '@/lib/format';

const props = defineProps<{ storeId: number }>();
const router = useRouter();
const session = useSessionStore();
const cartQuery = useCart();
const checkout = useCheckout();

const group = computed(() => cartQuery.data.value?.find((g) => g.store_id === props.storeId));
const form = reactive({
  recipient_name: session.me?.name ?? '',
  recipient_phone: '',
  shipping_address: '',
  buyer_note: '',
});
const error = ref<string | null>(null);
const needsMembership = ref(false);

async function submit() {
  error.value = null;
  needsMembership.value = false;
  try {
    const res = await checkout.mutateAsync({ store_id: props.storeId, ...form });
    session.load(true);
    if (res.payment_url) {
      // Konfirmasi pembayaran terjadi di Koperasi Digital (PIN + pilih rekening).
      window.location.assign(res.payment_url);
    } else {
      router.push({ name: 'order', params: { id: res.order.id }, query: { payment: 'retry' } });
    }
  } catch (err) {
    needsMembership.value = apiErrorCode(err) === 'MEMBERSHIP_REQUIRED_FOR_PAYMENT';
    error.value = apiErrorMessage(err, 'Checkout gagal.');
  }
}
</script>

<template>
  <MarketShell>
    <h1 class="mb-6 text-2xl font-bold">Checkout</h1>
    <Skeleton v-if="cartQuery.isPending.value" :rows="3" />
    <EmptyState v-else-if="!group" icon="🛒" title="Tidak ada barang untuk toko ini">
      <template #action><RouterLink to="/cart" class="btn-primary">Kembali ke keranjang</RouterLink></template>
    </EmptyState>

    <div v-else class="grid gap-6 lg:grid-cols-3">
      <form class="card space-y-4 lg:col-span-2" @submit.prevent="submit">
        <h2 class="text-lg font-semibold">Alamat Pengiriman</h2>
        <div v-if="error" role="alert" class="rounded-lg border border-secondary-200 bg-secondary-50 px-4 py-3 text-sm text-secondary-800">
          {{ error }}
          <a v-if="needsMembership" href="/koperasi/dashboard/membership" class="mt-1 block font-semibold underline">Aktifkan keanggotaan koperasi</a>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label class="label" for="rn">Nama Penerima</label>
            <input id="rn" v-model="form.recipient_name" required minlength="3" maxlength="150" class="input" autocomplete="name" />
          </div>
          <div>
            <label class="label" for="rp">No. HP Penerima</label>
            <input id="rp" v-model="form.recipient_phone" required type="tel" pattern="\+?[0-9]{10,15}" class="input" autocomplete="tel" placeholder="08xxxxxxxxxx" />
          </div>
        </div>
        <div>
          <label class="label" for="addr">Alamat Lengkap</label>
          <textarea id="addr" v-model="form.shipping_address" required minlength="10" maxlength="1000" rows="3" class="input" autocomplete="street-address" placeholder="Jalan, nomor, RT/RW, kelurahan, kecamatan, kota, kode pos" />
        </div>
        <div>
          <label class="label" for="note">Catatan untuk Penjual (opsional)</label>
          <input id="note" v-model="form.buyer_note" maxlength="255" class="input" />
        </div>
        <p class="text-xs text-primary-500">Ongkos kirim disepakati langsung dengan penjual. Pembayaran memakai Koperasi Pay (saldo simpanan).</p>
        <button type="submit" class="btn-primary w-full sm:w-auto" :disabled="checkout.isPending.value">
          {{ checkout.isPending.value ? 'Memproses...' : 'Lanjut ke Pembayaran' }}
        </button>
      </form>

      <aside class="card h-fit">
        <h2 class="mb-3 text-lg font-semibold">{{ group.store_name }}</h2>
        <ul class="space-y-2 text-sm">
          <li v-for="it in group.items" :key="it.product_id" class="flex justify-between gap-3">
            <span class="truncate">{{ it.qty }}× {{ it.name }}</span>
            <span class="shrink-0">{{ formatRupiah(memberUnitPrice(it) * it.qty) }}</span>
          </li>
        </ul>
        <div class="mt-4 flex justify-between border-t border-primary-100 pt-3">
          <span class="font-semibold">Total</span>
          <span class="font-display text-xl font-bold text-primary-800">{{ formatRupiah(group.subtotal) }}</span>
        </div>
      </aside>
    </div>
  </MarketShell>
</template>
