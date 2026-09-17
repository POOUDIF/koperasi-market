import type { OrderStatus } from '@/types';

export function formatRupiah(amount: number | string | null | undefined): string {
  const n = typeof amount === 'string' ? parseFloat(amount) : (amount ?? 0);
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(
    Number.isFinite(n) ? n : 0,
  );
}

export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '-';
  const d = new Date(iso.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return '-';
  return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(d);
}

export const ORDER_STATUS: Record<OrderStatus, { label: string; tone: string }> = {
  pending_payment: { label: 'Menunggu Pembayaran', tone: 'bg-gold-100 text-gold-800' },
  paid: { label: 'Dibayar — Diproses Penjual', tone: 'bg-blue-100 text-blue-800' },
  shipped: { label: 'Dikirim', tone: 'bg-primary-100 text-primary-700' },
  completed: { label: 'Selesai', tone: 'bg-primary-700 text-white' },
  cancelled: { label: 'Dibatalkan', tone: 'bg-secondary-100 text-secondary-800' },
};

/** Harga efektif untuk anggota koperasi (Koperasi Pay hanya untuk anggota). */
export function memberUnitPrice(p: { price: number; member_price: number | null }): number {
  return p.member_price ?? p.price;
}
