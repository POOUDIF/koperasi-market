<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Error bisnis JDC Account. Nama & kontrak factory generik (badRequest,
 * unauthorized, ...) sama dengan layanan lain karena dipakai library
 * bersama (Validator, Ratelimit, MY_Model).
 *
 * Pesan di sini ditampilkan ke pengguna di halaman login/daftar, jadi
 * berbahasa Indonesia yang ramah dan TIDAK membocorkan keberadaan akun.
 */
class Api_exception extends Exception {

    public $status;
    public $code_name;

    public function __construct($code_name, $message, $status = 400) {
        parent::__construct($message);
        $this->code_name = $code_name;
        $this->status    = $status;
    }

    public static function invalidCredentials() { return new self('INVALID_CREDENTIALS', 'Email atau kata sandi tidak valid.', 401); }
    public static function loginLocked()        { return new self('LOGIN_LOCKED', 'Terlalu banyak percobaan masuk yang gagal. Coba lagi dalam 15 menit.', 429); }
    public static function emailExists()        { return new self('EMAIL_EXISTS', 'Email sudah terdaftar. Silakan masuk atau gunakan fitur lupa kata sandi.', 409); }
    public static function accountSuspended()   { return new self('ACCOUNT_SUSPENDED', 'Akun Anda dinonaktifkan. Hubungi pengurus koperasi.', 403); }
    public static function otpInvalid()         { return new self('OTP_INVALID', 'Kode verifikasi salah atau sudah kedaluwarsa.', 400); }
    public static function resetTokenInvalid()  { return new self('RESET_TOKEN_INVALID', 'Tautan reset kata sandi tidak valid atau sudah kedaluwarsa.', 400); }
    public static function weakPassword($msg)   { return new self('WEAK_PASSWORD', $msg, 400); }

    public static function unauthorized($msg = 'Sesi tidak valid, silakan masuk kembali.') { return new self('UNAUTHORIZED', $msg, 401); }
    public static function forbidden($msg = 'Akses ditolak.') { return new self('FORBIDDEN', $msg, 403); }
    public static function notFound($msg = 'Halaman tidak ditemukan.') { return new self('NOT_FOUND', $msg, 404); }
    public static function badRequest($msg)  { return new self('BAD_REQUEST', $msg, 400); }
    public static function tooManyRequests() { return new self('TOO_MANY_REQUESTS', 'Terlalu banyak permintaan. Silakan coba lagi beberapa saat lagi.', 429); }
    public static function server()          { return new self('SERVER_ERROR', 'Terjadi kesalahan pada server. Silakan coba lagi.', 500); }
}
