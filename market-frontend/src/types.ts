// Tipe selaras dengan response API Marketplace (apps/market/controllers/api/v1).
// Nilai uang dikirim backend sebagai number (Money::out()).

export interface Membership {
  available: boolean;
  is_member: boolean;
  kyc_completed: boolean;
}

export interface Store {
  id: number;
  owner_customer_id?: number;
  name: string;
  slug: string;
  description: string | null;
  city: string;
  status: 'active' | 'suspended';
  created_at: string;
  owner_name?: string;
  owner_email?: string;
  product_count?: number;
}

export interface Me {
  id: number;
  name: string;
  email: string;
  role: 'customer' | 'admin';
  membership: Membership;
  store: Store | null;
  cart_count: number;
}

export interface Product {
  id: number;
  store_id: number;
  name: string;
  description: string | null;
  price: number;
  member_price: number | null;
  stock: number;
  weight_gram: number;
  image_url: string;
  status: 'active' | 'inactive';
  created_at: string;
  store_name?: string;
  store_slug?: string;
  store_city?: string;
}

export interface Paged<T> {
  page: number;
  per_page: number;
  total: number;
  items: T[];
}

export interface CartItem {
  product_id: number;
  qty: number;
  name: string;
  price: number;
  member_price: number | null;
  stock: number;
  image_url: string;
  available: boolean;
  store_id: number;
  store_name: string;
  store_slug: string;
}

export interface CartStoreGroup {
  store_id: number;
  store_name: string;
  store_slug: string;
  items: CartItem[];
  subtotal: number;
}

export type OrderStatus = 'pending_payment' | 'paid' | 'shipped' | 'completed' | 'cancelled';

export interface OrderItem {
  product_id: number;
  product_name: string;
  unit_price: number;
  qty: number;
  line_total: number;
}

export interface Order {
  id: number;
  order_number: string;
  buyer_customer_id: number;
  store_id: number;
  status: OrderStatus;
  subtotal: number;
  shipping_fee: number;
  total: number;
  recipient_name: string;
  recipient_phone: string;
  shipping_address: string;
  buyer_note: string;
  tracking_number: string | null;
  payment_intent_id: string | null;
  payout_action: 'none' | 'settle' | 'refund';
  payout_status: 'none' | 'pending' | 'done' | 'failed';
  cancel_reason: string | null;
  cancelled_by: 'buyer' | 'seller' | 'admin' | 'system' | null;
  paid_at: string | null;
  shipped_at: string | null;
  completed_at: string | null;
  cancelled_at: string | null;
  created_at: string;
  store_name?: string;
  store_slug?: string;
  buyer_name?: string;
  store?: { id: number; name: string; slug: string };
  items?: OrderItem[];
}

export interface ProductInput {
  name: string;
  description: string;
  price: string;
  member_price: string;
  stock: number;
  weight_gram: number;
  image_url: string;
  status: 'active' | 'inactive';
}
