<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Error bisnis Marketplace. Status HTTP melekat pada exception.
 * Resource milik orang lain dijawab 404, bukan 403 (anti-enumerasi ID).
 */
class Api_exception extends Exception {

    public $status;
    public $code_name;

    public function __construct($code_name, $message, $status = 400) {
        parent::__construct($message);
        $this->code_name = $code_name;
        $this->status    = $status;
    }

    /* ---------- Toko & produk ---------- */
    public static function storeNotFound()      { return new self('STORE_NOT_FOUND', 'toko tidak ditemukan', 404); }
    public static function storeExists()        { return new self('STORE_EXISTS', 'Anda sudah memiliki toko', 409); }
    public static function noStore()            { return new self('NO_STORE', 'buka toko terlebih dahulu', 404); }
    public static function storeSuspended()     { return new self('STORE_SUSPENDED', 'toko sedang ditangguhkan', 403); }
    public static function sellerNotEligible()  { return new self('SELLER_NOT_ELIGIBLE', 'membuka toko hanya untuk anggota koperasi aktif yang sudah melengkapi KYC', 422); }
    public static function productNotFound()    { return new self('PRODUCT_NOT_FOUND', 'produk tidak ditemukan', 404); }
    public static function invalidMemberPrice() { return new self('INVALID_MEMBER_PRICE', 'harga anggota harus lebih dari 0 dan tidak melebihi harga normal', 400); }

    /* ---------- Keranjang & checkout ---------- */
    public static function outOfStock($name)    { return new self('OUT_OF_STOCK', "stok \"{$name}\" tidak mencukupi", 422); }
    public static function productUnavailable($name) { return new self('PRODUCT_UNAVAILABLE', "\"{$name}\" sudah tidak dijual", 422); }
    public static function cartEmpty()          { return new self('CART_EMPTY', 'keranjang untuk toko ini kosong', 422); }
    public static function ownProduct()         { return new self('OWN_PRODUCT', 'Anda tidak dapat membeli produk dari toko sendiri', 422); }
    public static function buyerNotMember()     { return new self('MEMBERSHIP_REQUIRED_FOR_PAYMENT', 'pembayaran Koperasi Pay hanya untuk anggota koperasi aktif — aktifkan keanggotaan di Koperasi Digital', 422); }
    public static function sellerUnavailable()  { return new self('SELLER_UNAVAILABLE', 'penjual sedang tidak dapat menerima pembayaran', 422); }

    /* ---------- Pesanan & pembayaran ---------- */
    public static function orderNotFound()      { return new self('ORDER_NOT_FOUND', 'pesanan tidak ditemukan', 404); }
    public static function orderInvalidState($action) { return new self('ORDER_INVALID_STATE', "pesanan tidak dapat {$action} pada status saat ini", 409); }
    public static function paymentUnavailable() { return new self('PAYMENT_UNAVAILABLE', 'layanan pembayaran koperasi sedang tidak tersedia, coba beberapa saat lagi', 503); }

    /* ---------- Generik ---------- */
    public static function sessionUnavailable() { return new self('SESSION_UNAVAILABLE', 'layanan sesi sedang tidak tersedia, coba beberapa saat lagi', 503); }
    public static function unauthorized($msg = 'silakan masuk terlebih dahulu') { return new self('UNAUTHORIZED', $msg, 401); }
    public static function forbidden($msg = 'akses ditolak: hak akses tidak mencukupi') { return new self('FORBIDDEN', $msg, 403); }
    public static function notFound($msg = 'endpoint tidak ditemukan') { return new self('NOT_FOUND', $msg, 404); }
    public static function badRequest($msg)  { return new self('BAD_REQUEST', $msg, 400); }
    public static function tooManyRequests() { return new self('TOO_MANY_REQUESTS', 'terlalu banyak permintaan, silakan coba lagi beberapa saat kemudian', 429); }
    public static function server()          { return new self('SERVER_ERROR', 'terjadi kesalahan pada server', 500); }
}
