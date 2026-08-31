<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Padanan sentinel error Go (§10.2).
 *
 * Setiap kondisi bisnis punya satu factory. Controller tidak perlu memetakan
 * error → status HTTP: status sudah melekat pada exception-nya.
 *
 * Catatan otorisasi (§2.1 aturan 4): akses ke resource milik anggota lain
 * dikembalikan sebagai 404, BUKAN 403, agar ID tidak bisa dienumerasi.
 */
class Api_exception extends Exception {

    /** @var int kode status HTTP */
    public $status;

    /** @var string kode mesin, mis. INSUFFICIENT_BALANCE */
    public $code_name;

    public function __construct($code_name, $message, $status = 400) {
        parent::__construct($message);
        $this->code_name = $code_name;
        $this->status    = $status;
    }

    /* ---------- Auth & akun ---------- */
    public static function invalidCredentials() { return new self('INVALID_CREDENTIALS', 'email atau password tidak valid', 401); }
    public static function emailExists()        { return new self('EMAIL_EXISTS', 'email sudah terdaftar', 409); }
    public static function userNotFound()       { return new self('USER_NOT_FOUND', 'akun tidak ditemukan', 404); }
    public static function emailNotVerified()   { return new self('EMAIL_NOT_VERIFIED', 'email belum diverifikasi, periksa kotak masuk Anda', 403); }
    public static function accountSuspended()   { return new self('ACCOUNT_SUSPENDED', 'akun tidak aktif atau diblokir, hubungi admin koperasi', 403); }
    public static function nikTaken()           { return new self('NIK_TAKEN', 'NIK sudah terdaftar pada akun lain', 409); }

    /* ---------- Simpanan ---------- */
    public static function savingsAccountNotFound() { return new self('SAVINGS_ACCOUNT_NOT_FOUND', 'rekening simpanan tidak ditemukan', 404); }
    public static function savingsProductNotFound() { return new self('SAVINGS_PRODUCT_NOT_FOUND', 'produk simpanan tidak ditemukan', 404); }
    public static function accountNotActive()       { return new self('ACCOUNT_NOT_ACTIVE', 'rekening simpanan tidak aktif', 422); }
    public static function depositBelowMinimum($m)  { return new self('DEPOSIT_BELOW_MINIMUM', 'jumlah setoran di bawah minimum produk: minimum Rp ' . number_format((float) $m, 0, ',', '.'), 422); }
    public static function depositRequestNotFound() { return new self('DEPOSIT_REQUEST_NOT_FOUND', 'permohonan setoran tidak ditemukan', 404); }
    public static function depositAlreadyReviewed() { return new self('DEPOSIT_ALREADY_REVIEWED', 'permohonan setoran sudah direview sebelumnya', 422); }

    /* ---------- Pembiayaan ---------- */
    public static function financingNotFound()      { return new self('FINANCING_NOT_FOUND', 'pengajuan pembiayaan tidak ditemukan', 404); }
    public static function financingNotPending()    { return new self('FINANCING_NOT_PENDING', 'pengajuan sudah pernah diproses sebelumnya', 409); }
    public static function financingNumberBusy()    { return new self('FINANCING_NUMBER_BUSY', 'sistem sibuk membuat nomor pembiayaan, silakan coba lagi', 503); }
    public static function duplicateFinancingNumber() { return new self('DUPLICATE_FINANCING_NUMBER', 'nomor pembiayaan bentrok', 409); }
    public static function installmentNotFound()    { return new self('INSTALLMENT_NOT_FOUND', 'cicilan tidak ditemukan', 404); }
    public static function installmentAlreadyPaid() { return new self('INSTALLMENT_ALREADY_PAID', 'cicilan sudah dibayar sebelumnya', 409); }
    public static function insufficientBalance()    { return new self('INSUFFICIENT_BALANCE', 'saldo rekening tidak mencukupi', 422); }

    /* ---------- Emas ---------- */
    public static function goldPriceUnavailable()    { return new self('GOLD_PRICE_UNAVAILABLE', 'harga emas belum tersedia, hubungi admin koperasi', 503); }
    public static function goldLimitExceeded($max)   { return new self('GOLD_LIMIT_EXCEEDED', "maksimal transaksi emas adalah {$max} gram per transaksi", 400); }
    public static function goldInsufficientHolding() { return new self('GOLD_INSUFFICIENT_HOLDING', 'saldo emas Anda tidak mencukupi untuk penjualan ini', 422); }

    /* ---------- Generik ---------- */
    public static function unauthorized($msg = 'sesi tidak valid, silakan login kembali') { return new self('UNAUTHORIZED', $msg, 401); }
    public static function forbidden($msg = 'akses ditolak: hak akses tidak mencukupi')   { return new self('FORBIDDEN', $msg, 403); }
    public static function notFound($msg = 'endpoint tidak ditemukan') { return new self('NOT_FOUND', $msg, 404); }
    public static function badRequest($msg)  { return new self('BAD_REQUEST', $msg, 400); }
    public static function tooManyRequests() { return new self('TOO_MANY_REQUESTS', 'terlalu banyak permintaan, silakan coba lagi beberapa saat kemudian', 429); }
    public static function server()          { return new self('SERVER_ERROR', 'terjadi kesalahan pada server', 500); }
}
