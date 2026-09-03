// Tipe data selaras dengan response backend CI3 (application/config/routes.php
// + application/controllers/api/v1/*.php). Semua nilai uang/gram dikirim backend
// sebagai number (lihat Money::out() di backend) — aman dipakai langsung sebagai number di sini.

export type UserRole = 'anggota' | 'pengurus' | 'admin' | 'super_admin';
export type UserStatus = 'active' | 'inactive' | 'banned';

export interface User {
  id: number;
  nama_lengkap: string;
  email: string;
  role: UserRole;
  wallet_address: string | null;
  status: UserStatus;
  created_at: string;
  updated_at: string;
}

export const ADMIN_ROLES: UserRole[] = ['pengurus', 'admin', 'super_admin'];

export interface AuthResponse {
  token: string;
  user: User;
}

export interface RegisterResponse {
  message: string;
  user_id: number;
}

// ---------------------------------------------------------------------------
// KYC
// ---------------------------------------------------------------------------

export interface KycProfile {
  user_id?: number;
  nik: string;
  phone_number: string;
  address: string;
  job_title: string;
  monthly_income: number;
  emergency_contact_name: string;
  emergency_contact_phone: string;
  created_at?: string;
  updated_at?: string;
}

// ---------------------------------------------------------------------------
// Simpanan
// ---------------------------------------------------------------------------

export type AccountStatus = 'active' | 'frozen' | 'closed';
export type AkadType = 'Wadiah' | 'Mudharabah';

export interface SavingsProduct {
  id: number;
  name: string;
  akad_type: AkadType;
  min_deposit: number;
  profit_sharing_ratio: number;
  is_mandatory: boolean;
}

export interface SavingsAccount {
  id: number;
  user_id: number;
  savings_product_id: number;
  product_name?: string;
  akad_type?: AkadType;
  balance: number;
  status: AccountStatus;
  created_at: string;
}

export type RequestStatus = 'pending' | 'approved' | 'rejected';

export interface DepositRequest {
  id: number;
  account_id: number;
  user_id?: number;
  amount: number;
  payment_method: string;
  proof_image_url: string | null;
  reference_id: string;
  status: RequestStatus;
  reviewed_by: number | null;
  reviewed_at: string | null;
  created_at: string;
}

export interface WithdrawRequest {
  id: number;
  account_id: number;
  user_id?: number;
  amount: number;
  reference_id: string;
  status: RequestStatus;
  reviewed_by: number | null;
  reviewed_at: string | null;
  created_at: string;
}

// ---------------------------------------------------------------------------
// Pembiayaan
// ---------------------------------------------------------------------------

export type FinancingStatus = 'pending' | 'approved' | 'paid' | 'rejected';

export interface Financing {
  id: number;
  user_id: number;
  financing_number: string;
  akad: 'murabahah';
  principal_amount: number;
  margin_amount: number;
  total_payable: number;
  duration_months: number;
  status: FinancingStatus;
  reviewed_by: number | null;
  reviewed_at: string | null;
  created_at: string;
}

export type InstallmentStatus = 'unpaid' | 'paid';

export interface FinancingInstallment {
  id: number;
  financing_id: number;
  installment_number: number;
  amount_due: number;
  amount_paid: number;
  due_date: string;
  status: InstallmentStatus;
  paid_at: string | null;
}

// ---------------------------------------------------------------------------
// Emas
// ---------------------------------------------------------------------------

export interface GoldPrice {
  buy_price_per_gram: number;
  sell_price_per_gram: number;
  updated_at: string;
}

export type GoldTxType = 'buy' | 'sell';
export type GoldTxStatus = 'pending' | 'processing' | 'success' | 'failed';

export interface GoldTransaction {
  id: number;
  user_id: number;
  type: GoldTxType;
  gram_amount: number;
  price_per_gram: number;
  total_rupiah: number;
  tx_hash: string | null;
  status: GoldTxStatus;
  created_at: string;
}

export interface GoldHolding {
  gram_holding: number;
}

// ---------------------------------------------------------------------------
// Admin
// ---------------------------------------------------------------------------

// Bentuk paginasi backend TIDAK pakai amplop generik "data" — tiap endpoint
// mengembalikan field bernama sesuai resource-nya (lihat Admin.php/paging()).
export interface PageMeta {
  page: number;
  per_page: number;
  total?: number;
}

export interface UsersPage extends PageMeta {
  users: User[];
  total: number;
}

export interface DepositRequestsPage extends PageMeta {
  deposit_requests: DepositRequest[];
  total: number;
}

export interface WithdrawRequestsPage extends PageMeta {
  withdraw_requests: WithdrawRequest[];
  total: number;
}

export interface FinancingsPage extends PageMeta {
  financings: Financing[];
  total: number;
}

export interface GoldTxPage extends PageMeta {
  transactions: GoldTransaction[];
  total: number;
}

export interface SavingsTxRow {
  id: number;
  account_id: number;
  type: 'deposit' | 'withdraw';
  amount: number;
  reference_id: string;
  created_at: string;
}

export interface SavingsTxPage extends PageMeta {
  transactions: SavingsTxRow[];
  total: number;
}

export interface GoldHoldingResponse {
  net_gram: number;
  transactions: GoldTransaction[];
  page: number;
  per_page: number;
}

export interface PayInstallmentResponse {
  message: string;
  remaining_unpaid: number;
  financing_settled: boolean;
}

export interface ApiErrorBody {
  error: string;
}
