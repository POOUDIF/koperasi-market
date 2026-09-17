<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import MarketShell from '@/components/MarketShell.vue';
import Skeleton from '@/components/Skeleton.vue';
import EmptyState from '@/components/EmptyState.vue';
import Pagination from '@/components/Pagination.vue';
import OrderStatusBadge from '@/components/OrderStatusBadge.vue';
import {
  useMyStore, useSaveProduct, useSaveStore, useSellerCancelOrder, useSellerOrders, useSellerProducts, useShipOrder,
} from '@/composables/useSeller';
import { useSessionStore } from '@/stores/session';
import { apiErrorMessage } from '@/lib/api';
import { ORDER_STATUS, formatDate, formatRupiah } from '@/lib/format';
import type { Order, Product, ProductInput } from '@/types';

const session = useSessionStore();
const storeQuery = useMyStore();
const store = computed(() => storeQuery.data.value);
const tab = ref<'orders' | 'products' | 'store'>('orders');

/* ------------------------------------------------------------- toko */
const storeForm = reactive({ name: '', description: '', city: '' });
watch(store, (s) => {
  if (s) Object.assign(storeForm, { name: s.name, description: s.description ?? '', city: s.city });
}, { immediate: true });
const saveStore = useSaveStore(() => !store.value);

async function submitStore() {
  try {
    await saveStore.mutateAsync({ ...storeForm });
    toast.success(store.value ? 'Profil toko disimpan.' : 'Toko berhasil dibuka!');
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Gagal menyimpan toko.'));
  }
}

/* ----------------------------------------------------------- produk */
const productsQuery = useSellerProducts();
const saveProduct = useSaveProduct();
const editing = ref<Product | null>(null);
const showForm = ref(false);
const emptyProduct = (): ProductInput => ({ name: '', description: '', price: '', member_price: '', stock: 0, weight_gram: 0, image_url: '', status: 'active' });
const productForm = reactive<ProductInput>(emptyProduct());

function openProduct(p: Product | null) {
  editing.value = p;
  Object.assign(productForm, p
    ? { name: p.name, description: p.description ?? '', price: String(p.price), member_price: p.member_price === null ? '' : String(p.member_price),
        stock: p.stock, weight_gram: p.weight_gram, image_url: p.image_url, status: p.status }
    : emptyProduct());
  showForm.value = true;
}

async function submitProduct() {
  try {
    await saveProduct.mutateAsync({ id: editing.value?.id, input: { ...productForm } });
    toast.success('Produk disimpan.');
    showForm.value = false;
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Gagal menyimpan produk.'));
  }
}

/* ---------------------------------------------------------- pesanan */
const orderStatus = ref('paid');
const orderPage = ref(1);
watch(orderStatus, () => (orderPage.value = 1));
const ordersQuery = useSellerOrders(() => ({ status: orderStatus.value, page: orderPage.value }));
const shipOrder = useShipOrder();
const cancelOrder = useSellerCancelOrder();

async function ship(o: Order) {
  const resi = prompt(`Nomor resi pengiriman untuk ${o.order_number}:`);
  if (!resi) return;
  try {
    await shipOrder.mutateAsync({ id: o.id, tracking_number: resi.trim() });
    toast.success('Pesanan ditandai dikirim.');
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Gagal memperbarui pesanan.'));
  }
}

async function cancel(o: Order) {
  const reason = prompt(`Alasan membatalkan ${o.order_number} (dana dikembalikan ke pembeli):`);
  if (!reason) return;
  try {
    await cancelOrder.mutateAsync({ id: o.id, reason: reason.trim() });
    toast.success('Pesanan dibatalkan.');
  } catch (err) {
    toast.error(apiErrorMessage(err, 'Gagal membatalkan pesanan.'));
  }
}
</script>

<template>
  <MarketShell>
    <Skeleton v-if="storeQuery.isPending.value" :rows="3" />

    <!-- Belum punya toko -->
    <div v-else-if="!store" class="mx-auto max-w-2xl">
      <h1 class="mb-2 text-2xl font-bold">Buka Toko</h1>
      <p class="mb-6 text-sm text-primary-600">
        Berjualan di Marketplace JDC khusus anggota koperasi aktif yang sudah melengkapi KYC.
        Dana penjualan masuk ke Simpanan Sukarela Anda setelah pembeli menerima pesanan.
      </p>
      <div v-if="!session.me?.membership.is_member || !session.me?.membership.kyc_completed" class="mb-6 rounded-lg border border-gold-200 bg-gold-50 px-4 py-3 text-sm text-gold-800">
        Anda belum memenuhi syarat.
        <a href="/koperasi/dashboard/membership" class="font-semibold underline">Lengkapi KYC & aktifkan keanggotaan</a>.
      </div>
      <form class="card space-y-4" @submit.prevent="submitStore">
        <div><label class="label" for="sn">Nama Toko</label><input id="sn" v-model="storeForm.name" required minlength="3" maxlength="100" class="input" /></div>
        <div><label class="label" for="sc">Kota</label><input id="sc" v-model="storeForm.city" required minlength="3" maxlength="100" class="input" /></div>
        <div><label class="label" for="sd">Deskripsi</label><textarea id="sd" v-model="storeForm.description" maxlength="2000" rows="4" class="input" /></div>
        <button type="submit" class="btn-primary" :disabled="saveStore.isPending.value">Buka Toko</button>
      </form>
    </div>

    <!-- Dashboard penjual -->
    <div v-else>
      <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
          <p class="text-xs font-semibold uppercase tracking-wider text-gold-700">Toko Saya</p>
          <h1 class="text-2xl font-bold">{{ store.name }}</h1>
          <p v-if="store.status === 'suspended'" class="mt-1 text-sm text-secondary-700">Toko ditangguhkan admin — produk tidak tampil di katalog.</p>
        </div>
        <RouterLink :to="{ name: 'store', params: { slug: store.slug } }" class="btn-secondary">Lihat Halaman Toko</RouterLink>
      </div>

      <nav class="mb-6 flex gap-2 border-b border-primary-100" role="tablist">
        <button v-for="t in ([['orders', 'Pesanan'], ['products', 'Produk'], ['store', 'Profil Toko']] as const)" :key="t[0]" role="tab"
          :aria-selected="tab === t[0]"
          class="-mb-px border-b-2 px-4 py-2 text-sm font-semibold"
          :class="tab === t[0] ? 'border-primary-700 text-primary-800' : 'border-transparent text-primary-500 hover:text-primary-700'"
          @click="tab = t[0]">{{ t[1] }}</button>
      </nav>

      <!-- Pesanan -->
      <section v-if="tab === 'orders'">
        <select v-model="orderStatus" class="input mb-4 !w-auto" aria-label="Filter status">
          <option value="">Semua status</option>
          <option v-for="(v, k) in ORDER_STATUS" :key="k" :value="k">{{ v.label }}</option>
        </select>
        <Skeleton v-if="ordersQuery.isPending.value" :rows="3" />
        <EmptyState v-else-if="!ordersQuery.data.value?.items.length" icon="📦" title="Tidak ada pesanan" />
        <div v-else class="space-y-3">
          <article v-for="o in ordersQuery.data.value.items" :key="o.id" class="card !py-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="text-sm font-semibold">{{ o.order_number }} · {{ o.buyer_name }}</p>
                <p class="text-xs text-primary-500">{{ formatDate(o.created_at) }}</p>
              </div>
              <div class="flex items-center gap-3">
                <OrderStatusBadge :status="o.status" />
                <span class="font-display font-bold">{{ formatRupiah(o.total) }}</span>
              </div>
            </div>
            <ul class="mt-2 text-sm text-primary-700">
              <li v-for="it in o.items" :key="it.product_id">{{ it.qty }}× {{ it.product_name }}</li>
            </ul>
            <p class="mt-2 text-xs text-primary-500">Kirim ke: {{ o.recipient_name }} ({{ o.recipient_phone }}) — {{ o.shipping_address }}</p>
            <p v-if="o.buyer_note" class="text-xs text-primary-500">Catatan: {{ o.buyer_note }}</p>
            <p v-if="o.tracking_number" class="text-xs">Resi: {{ o.tracking_number }}</p>
            <p v-if="o.status === 'completed'" class="text-xs text-primary-600">
              {{ o.payout_status === 'done' ? 'Dana sudah masuk ke Simpanan Sukarela Anda.' : 'Pencairan dana sedang diproses.' }}
            </p>
            <div v-if="o.status === 'paid'" class="mt-3 flex gap-2">
              <button class="btn-primary !py-1.5" :disabled="shipOrder.isPending.value" @click="ship(o)">Tandai Dikirim</button>
              <button class="btn-secondary !py-1.5" :disabled="cancelOrder.isPending.value" @click="cancel(o)">Batalkan</button>
            </div>
          </article>
          <Pagination :page="orderPage" :per-page="ordersQuery.data.value.per_page" :total="ordersQuery.data.value.total" @update:page="(p) => (orderPage = p)" />
        </div>
      </section>

      <!-- Produk -->
      <section v-else-if="tab === 'products'">
        <button class="btn-primary mb-4" @click="openProduct(null)">+ Tambah Produk</button>

        <form v-if="showForm" class="card mb-6 space-y-4" @submit.prevent="submitProduct">
          <h2 class="text-lg font-semibold">{{ editing ? 'Ubah Produk' : 'Produk Baru' }}</h2>
          <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2"><label class="label" for="pn">Nama Produk</label><input id="pn" v-model="productForm.name" required minlength="3" maxlength="150" class="input" /></div>
            <div><label class="label" for="pp">Harga Normal (Rp)</label><input id="pp" v-model="productForm.price" required inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,2})?" class="input" /></div>
            <div><label class="label" for="pm">Harga Anggota (Rp, opsional)</label><input id="pm" v-model="productForm.member_price" inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,2})?" class="input" /></div>
            <div><label class="label" for="ps">Stok</label><input id="ps" v-model.number="productForm.stock" type="number" min="0" required class="input" /></div>
            <div><label class="label" for="pw">Berat (gram)</label><input id="pw" v-model.number="productForm.weight_gram" type="number" min="0" class="input" /></div>
            <div class="sm:col-span-2"><label class="label" for="pi">URL Gambar (https://)</label><input id="pi" v-model="productForm.image_url" type="url" maxlength="500" class="input" placeholder="https://..." /></div>
            <div class="sm:col-span-2"><label class="label" for="pd">Deskripsi</label><textarea id="pd" v-model="productForm.description" rows="4" maxlength="5000" class="input" /></div>
            <div>
              <label class="label" for="pst">Status</label>
              <select id="pst" v-model="productForm.status" class="input"><option value="active">Dijual</option><option value="inactive">Disembunyikan</option></select>
            </div>
          </div>
          <div class="flex gap-2">
            <button type="submit" class="btn-primary" :disabled="saveProduct.isPending.value">Simpan</button>
            <button type="button" class="btn-secondary" @click="showForm = false">Batal</button>
          </div>
        </form>

        <Skeleton v-if="productsQuery.isPending.value" :rows="3" />
        <EmptyState v-else-if="!productsQuery.data.value?.length" icon="🧺" title="Belum ada produk" />
        <div v-else class="card overflow-x-auto !p-0">
          <table class="w-full text-left text-sm">
            <thead class="border-b border-primary-100 bg-cream-50 text-xs uppercase text-primary-500">
              <tr><th class="px-4 py-3">Produk</th><th class="px-4 py-3">Harga</th><th class="px-4 py-3">Stok</th><th class="px-4 py-3">Status</th><th class="px-4 py-3"></th></tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
              <tr v-for="p in productsQuery.data.value" :key="p.id">
                <td class="px-4 py-3 font-medium">{{ p.name }}</td>
                <td class="px-4 py-3">{{ formatRupiah(p.price) }}<span v-if="p.member_price !== null" class="block text-xs text-gold-700">Anggota {{ formatRupiah(p.member_price) }}</span></td>
                <td class="px-4 py-3">{{ p.stock }}</td>
                <td class="px-4 py-3">{{ p.status === 'active' ? 'Dijual' : 'Disembunyikan' }}</td>
                <td class="px-4 py-3 text-right"><button class="text-sm font-semibold text-primary-700 hover:underline" @click="openProduct(p)">Ubah</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Profil toko -->
      <form v-else class="card max-w-2xl space-y-4" @submit.prevent="submitStore">
        <div><label class="label" for="sn2">Nama Toko</label><input id="sn2" v-model="storeForm.name" required minlength="3" maxlength="100" class="input" /></div>
        <div><label class="label" for="sc2">Kota</label><input id="sc2" v-model="storeForm.city" required minlength="3" maxlength="100" class="input" /></div>
        <div><label class="label" for="sd2">Deskripsi</label><textarea id="sd2" v-model="storeForm.description" maxlength="2000" rows="4" class="input" /></div>
        <button type="submit" class="btn-primary" :disabled="saveStore.isPending.value">Simpan</button>
      </form>
    </div>
  </MarketShell>
</template>
