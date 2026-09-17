<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Enkripsi simetris untuk data sensitif at-rest (refresh token di sesi BFF).
 * AES-256-GCM: kerahasiaan + integritas; ciphertext yang diubah akan gagal
 * didekripsi, bukan menghasilkan plaintext sampah.
 *
 * Kunci: 32 byte acak, base64, dari env SESSION_ENC_KEY. Buat dengan
 *   php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
 */
class Crypto {

    const CIPHER = 'aes-256-gcm';

    private $key;

    public function __construct() {
        $raw = base64_decode((string) env('SESSION_ENC_KEY', ''), TRUE);
        if ($raw === FALSE || strlen($raw) !== 32) {
            log_message('error', '[crypto] SESSION_ENC_KEY tidak diset / bukan 32 byte base64');
            $this->key = NULL;
            return;
        }
        $this->key = $raw;
    }

    public function encrypt($plaintext) {
        $this->assert_key();
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt((string) $plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === FALSE) { throw new RuntimeException('enkripsi gagal'); }
        return base64url_encode($iv . $tag . $ct);
    }

    /** @return string|null NULL bila ciphertext rusak/diubah. */
    public function decrypt($encoded) {
        $this->assert_key();
        $bin = base64url_decode((string) $encoded);
        if ($bin === FALSE || strlen($bin) < 29) { return NULL; }
        $pt = openssl_decrypt(substr($bin, 28), self::CIPHER, $this->key, OPENSSL_RAW_DATA,
            substr($bin, 0, 12), substr($bin, 12, 16));
        return $pt === FALSE ? NULL : $pt;
    }

    private function assert_key() {
        if ($this->key === NULL) { throw new RuntimeException('SESSION_ENC_KEY belum dikonfigurasi'); }
    }
}
