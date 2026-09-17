# Frontend Marketplace — Jawa Dwipa Cooperative

SPA Vue 3 + Vite + TypeScript di **`/market/`**. Katalog publik; keranjang, checkout,
pesanan, toko, dan admin butuh login SSO (JDC Account). Pembayaran lewat **Koperasi Pay**
(halaman konfirmasi di `/koperasi/pay/:id`). Arsitektur:
[`../DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md`](../DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md) §3–§4.

```bash
npm run dev:market      # dari root repo — http://localhost:5174/market/
npm run build:market    # vue-tsc + vite build → ../public_html/market
```

- `lib/api.ts` — axios dengan cookie sesi + `X-Requested-With`; `skipAuthRedirect` untuk panggilan publik.
- `lib/sso.ts` — login SSO; **SSO senyap** (`prompt=none`) sekali per sesi browser di halaman publik.
- `stores/session.ts` — `GET /me` (profil, status keanggotaan koperasi, toko, jumlah keranjang).
- `views/` — Home (katalog), Product, Store, Cart, Checkout, Orders, OrderDetail (polling status setelah kembali
  dari Koperasi Pay), `seller/SellerView` (toko, produk, pesanan), `admin/AdminView`.
