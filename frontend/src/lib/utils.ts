export function formatRupiah(amount: number | string): string {
  const n = typeof amount === 'string' ? parseFloat(amount) : amount;
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(Number.isFinite(n) ? n : 0);
}

export function formatGram(amount: number | string): string {
  const n = typeof amount === 'string' ? parseFloat(amount) : amount;
  return `${new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 4 }).format(n)} gr`;
}

export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '-';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '-';
  return new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(d);
}

export function formatDateShort(iso: string | null | undefined): string {
  if (!iso) return '-';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '-';
  return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }).format(d);
}

type BadgeTone = 'green' | 'gold' | 'red' | 'gray' | 'blue';

const TONE_CLASSES: Record<BadgeTone, string> = {
  green: 'bg-primary-100 text-primary-800',
  gold: 'bg-gold-100 text-gold-800',
  red: 'bg-secondary-100 text-secondary-800',
  gray: 'bg-gray-100 text-gray-700',
  blue: 'bg-blue-100 text-blue-800',
};

export function badgeClass(tone: BadgeTone): string {
  return TONE_CLASSES[tone];
}

const STATUS_TONE: Record<string, BadgeTone> = {
  active: 'green',
  approved: 'green',
  success: 'green',
  paid: 'green',
  pending: 'gold',
  processing: 'blue',
  frozen: 'gold',
  inactive: 'gray',
  rejected: 'red',
  failed: 'red',
  banned: 'red',
  closed: 'gray',
  unpaid: 'gray',
};

export function statusBadgeClass(status: string): string {
  return badgeClass(STATUS_TONE[status] ?? 'gray');
}

const STATUS_LABEL: Record<string, string> = {
  active: 'Aktif',
  approved: 'Disetujui',
  success: 'Berhasil',
  paid: 'Lunas',
  pending: 'Menunggu',
  processing: 'Diproses',
  frozen: 'Dibekukan',
  inactive: 'Nonaktif',
  rejected: 'Ditolak',
  failed: 'Gagal',
  banned: 'Diblokir',
  closed: 'Ditutup',
  unpaid: 'Belum Bayar',
};

export function statusLabel(status: string): string {
  return STATUS_LABEL[status] ?? status;
}

const ROLE_LABEL: Record<string, string> = {
  anggota: 'Anggota',
  pengurus: 'Pengurus',
  admin: 'Admin',
  super_admin: 'Super Admin',
};

export function roleLabel(role: string): string {
  return ROLE_LABEL[role] ?? role;
}
