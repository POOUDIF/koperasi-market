<script setup lang="ts">
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import DashboardShell from '@/components/DashboardShell.vue';
import Skeleton from '@/components/Skeleton.vue';
import { usePinStatus, useSetPin } from '@/composables/useMembership';
import { apiErrorMessage } from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { ACCOUNT_URL } from '@/lib/sso';

const pinQuery = usePinStatus();
const setPinMutation = useSetPin();

const pin = ref('');
const confirmPin = ref('');

const valid = computed(() => /^\d{6}$/.test(pin.value) && pin.value === confirmPin.value);

async function submit() {
  try {
    // Backend mensyaratkan login ulang ≤ 10 menit; bila perlu, interceptor
    // mengarahkan ke JDC Account (prompt=login) lalu kembali ke halaman ini.
    await setPinMutation.mutateAsync(pin.value);
    toast.success('PIN transaksi disimpan.');
    pin.value = '';
    confirmPin.value = '';
  } catch (err) {
    toast.error(apiErrorMessage(err, 'PIN gagal disimpan.'));
  }
}
</script>

<template>
  <DashboardShell>
    <div class="grid max-w-4xl gap-6 lg:grid-cols-2">
      <section class="card">
        <h2 class="text-lg font-semibold">PIN Transaksi</h2>
        <p class="mt-1 text-sm text-primary-500">
          PIN 6 digit wajib dimasukkan setiap membayar di Marketplace dengan saldo koperasi.
          Jangan berikan PIN kepada siapa pun, termasuk pengurus koperasi.
        </p>

        <Skeleton v-if="pinQuery.isPending.value" :rows="2" class="mt-4" />
        <div v-else class="mt-4">
          <p v-if="pinQuery.data.value?.locked_until" class="mb-4 rounded-lg border border-secondary-200 bg-secondary-50 px-4 py-3 text-sm text-secondary-800">
            PIN terkunci sampai {{ formatDate(pinQuery.data.value.locked_until) }} karena terlalu banyak percobaan salah.
          </p>
          <p class="mb-4 text-sm">
            Status:
            <span class="badge" :class="pinQuery.data.value?.has_pin ? 'bg-primary-100 text-primary-800' : 'bg-gold-100 text-gold-800'">
              {{ pinQuery.data.value?.has_pin ? 'PIN sudah dibuat' : 'Belum ada PIN' }}
            </span>
          </p>

          <form class="space-y-4" @submit.prevent="submit">
            <div>
              <label class="label" for="pin">{{ pinQuery.data.value?.has_pin ? 'PIN Baru' : 'Buat PIN' }}</label>
              <input id="pin" v-model="pin" type="password" inputmode="numeric" maxlength="6" autocomplete="new-password" class="input tracking-[0.4em]" placeholder="••••••" />
            </div>
            <div>
              <label class="label" for="pin2">Ulangi PIN</label>
              <input id="pin2" v-model="confirmPin" type="password" inputmode="numeric" maxlength="6" autocomplete="new-password" class="input tracking-[0.4em]" placeholder="••••••" />
            </div>
            <p class="text-xs text-primary-400">Hindari angka berulang atau berurutan (111111, 123456). Demi keamanan, Anda mungkin diminta memasukkan kata sandi akun lagi.</p>
            <button type="submit" class="btn-primary" :disabled="!valid || setPinMutation.isPending.value">
              {{ setPinMutation.isPending.value ? 'Menyimpan...' : 'Simpan PIN' }}
            </button>
          </form>
        </div>
      </section>

      <section class="card">
        <h2 class="text-lg font-semibold">Akun JDC</h2>
        <p class="mt-1 text-sm text-primary-500">
          Kata sandi, email, dan daftar perangkat yang sedang masuk dikelola di Akun JDC — berlaku untuk semua layanan
          (Koperasi Digital &amp; Marketplace).
        </p>
        <a :href="ACCOUNT_URL" class="btn-secondary mt-4">Buka Akun JDC</a>
      </section>
    </div>
  </DashboardShell>
</template>
