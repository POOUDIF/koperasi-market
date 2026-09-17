<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { toast } from 'vue-sonner';
import MarketShell from '@/components/MarketShell.vue';
import Skeleton from '@/components/Skeleton.vue';
import EmptyState from '@/components/EmptyState.vue';
import OrderStatusBadge from '@/components/OrderStatusBadge.vue';
import { useCancelOrder, useCompleteOrder, useOrder, usePayOrder } from '@/composables/useOrders';
import { apiErrorMessage } from '@/lib/api';
import { formatDate, formatRupiah } from '@/lib/format';

const props = defineProps<{ id: number }>();
const route = useRoute();
const orderQuery = useOrder(() => props.id);
const payOrder = usePayOrder();
const cancelOrder = useCancelOrder();
const completeOrder = useCompleteOrder();

const order = computed(() => orderQuery.data.value);

// Kembali dari Koperasi Pay: webhook bisa tiba sesaat setelah redirect.
// GET /orders/:id juga merekonsiliasi status ke koperasi, jadi cukup polling singkat.
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
  if (route.query.payment_intent) {
    let tries = 0;
    timer = setInterval(async () => {
      tries++;
      await orderQuery.refetch();
      if (order.value?.status !== 'pending_payment' || tries >= 5) clearInterval(timer);
    }, 1500);
  }
});
onBeforeUnmount(() => clearInterval(timer));

async function pay() {
  try {
    const { payment_url } = await payOrder.mutateAsync(props.id);
    window.location.assign(payment_url);
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Pembayaran belum bisa dimulai.'));
  }
}

async function cancel() {
  if (!confirm('Batalkan pesanan ini? Bila sudah dibayar, dana dikembalikan ke rekening koperasi Anda.')) return;
  try {
    await cancelOrder.mutateAsync({ id: props.id, reason: 'dibatalkan pembeli' });
    toast.success('Pesanan dibatalkan.');
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Pembatalan gagal.'));
  }
}

async function complete() {
  if (!confirm('Konfirmasi pesanan sudah diterima dengan baik? Dana akan diteruskan ke penjual.')) return;
  try {
    await completeOrder.mutateAsync({ id: props.id });
    toast.success('Terima kasih! Pesanan selesai.');
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Gagal menyelesaikan pesanan.'));
  }
}
</script>

<template>
  <MarketShell>
    <Skeleton v-if="orderQuery.isPending.value" :rows="4" />
    <EmptyState v-else-if="!order" icon="📦" title="Pesanan tidak ditemukan">
      <template #action><RouterLink to="/orders" class="btn-primary">Pesanan saya</RouterLink></template>
    </EmptyState>

    <div v-else class="grid gap-6 lg:grid-cols-3">
      <section class="card lg:col-span-2">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p class="text-xs text-primary-500">{{ order.order_number }} · {{ formatDate(order.created_at) }}</p>
            <h1 class="mt-1 text-xl font-bold">{{ order.store?.name }}</h1>
          </div>
          <OrderStatusBadge :status="order.status" />
        </div>

        <div v-if="order.status === 'pending_payment'" class="mt-4 rounded-lg border border-gold-200 bg-gold-50 px-4 py-3 text-sm text-gold-800">
          Menunggu pembayaran. Pesanan dibatalkan otomatis bila tidak dibayar dalam 24 jam.
        </div>
        <div v-if="order.status === 'cancelled'" class="mt-4 rounded-lg border border-secondary-200 bg-secondary-50 px-4 py-3 text-sm text-secondary-800">
          Dibatalkan {{ order.cancelled_by === 'buyer' ? 'oleh Anda' : order.cancelled_by === 'system' ? 'otomatis' : 'oleh penjual' }}: {{ order.cancel_reason }}.
          <span v-if="order.payout_action === 'refund'">
            {{ order.payout_status === 'done' ? 'Dana telah dikembalikan ke rekening koperasi Anda.' : 'Pengembalian dana sedang diproses.' }}
          </span>
        </div>

        <ul class="mt-6 divide-y divide-primary-50">
          <li v-for="it in order.items" :key="it.product_id" class="flex justify-between gap-4 py-3 text-sm">
            <span>{{ it.qty }}× {{ it.product_name }} <span class="text-primary-400">@ {{ formatRupiah(it.unit_price) }}</span></span>
            <span class="font-medium">{{ formatRupiah(it.line_total) }}</span>
          </li>
        </ul>
        <div class="flex justify-between border-t border-primary-100 pt-3">
          <span class="font-semibold">Total</span>
          <span class="font-display text-xl font-bold text-primary-800">{{ formatRupiah(order.total) }}</span>
        </div>

        <div class="mt-6 flex flex-wrap gap-3">
          <button v-if="order.status === 'pending_payment'" class="btn-primary" :disabled="payOrder.isPending.value" @click="pay">Bayar dengan Koperasi Pay</button>
          <button v-if="order.status === 'shipped'" class="btn-primary" :disabled="completeOrder.isPending.value" @click="complete">Pesanan Diterima</button>
          <button v-if="order.status === 'pending_payment' || order.status === 'paid'" class="btn-secondary" :disabled="cancelOrder.isPending.value" @click="cancel">Batalkan Pesanan</button>
        </div>
      </section>

      <aside class="card h-fit space-y-4 text-sm">
        <div>
          <h2 class="mb-1 text-base font-semibold">Pengiriman</h2>
          <p class="font-medium">{{ order.recipient_name }} · {{ order.recipient_phone }}</p>
          <p class="whitespace-pre-line text-primary-600">{{ order.shipping_address }}</p>
          <p v-if="order.tracking_number" class="mt-2">No. resi: <strong>{{ order.tracking_number }}</strong></p>
        </div>
        <div>
          <h2 class="mb-1 text-base font-semibold">Riwayat</h2>
          <ul class="space-y-1 text-primary-600">
            <li>Dibuat: {{ formatDate(order.created_at) }}</li>
            <li v-if="order.paid_at">Dibayar: {{ formatDate(order.paid_at) }}</li>
            <li v-if="order.shipped_at">Dikirim: {{ formatDate(order.shipped_at) }}</li>
            <li v-if="order.completed_at">Selesai: {{ formatDate(order.completed_at) }}</li>
            <li v-if="order.cancelled_at">Dibatalkan: {{ formatDate(order.cancelled_at) }}</li>
          </ul>
        </div>
      </aside>
    </div>
  </MarketShell>
</template>
