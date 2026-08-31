<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Klien HTTP tipis ke signer service eksternal (§16.4 Opsi 2).
 *
 * PENTING — batas keamanan yang disengaja: kelas ini TIDAK PERNAH menyentuh
 * OWNER_PRIVATE_KEY. Kunci penanda tangan hanya boleh hidup di proses signer
 * service (mis. Node.js + ethers.js, lihat signer-service/), yang berjalan
 * terpisah dari PHP-FPM/php -S dan tidak membaca .env aplikasi ini. Chain_client
 * hanya bicara HTTP ke signer lewat SIGNER_SERVICE_URL — kontrak dua endpoint:
 *
 *   POST /mint      { "to": "0x...", "units": "5000", "goldTxId": 42 } → { "txHash": "0x..." }
 *   GET  /receipt?hash=0x...                                          → { "status": 1|0 } | 204
 *
 * Selama SIGNER_SERVICE_URL atau GOLD_CONTRACT_ADDRESS belum diisi, is_ready()
 * mengembalikan FALSE dan Gold_worker tetap di mode log-only (status transaksi
 * emas tertahan di 'pending', tidak ada mint yang dicoba) — ini perilaku aman
 * yang disengaja, bukan kegagalan.
 */
class Chain_client {

    private $base;
    private $ready;

    public function __construct() {
        $this->base  = rtrim((string) env('SIGNER_SERVICE_URL', ''), '/');
        $this->ready = ($this->base !== '' && env('GOLD_CONTRACT_ADDRESS'));
    }

    public function is_ready() {
        return (bool) $this->ready;
    }

    /**
     * Minta signer service mengirim transaksi mint on-chain.
     *
     * @return string tx_hash
     * @throws Exception bila signer tidak merespons 200 atau tidak mengembalikan txHash.
     */
    public function mint($to, $units, $gold_tx_id) {
        $res = $this->_post('/mint', array(
            'to'       => $to,
            'units'    => (string) $units,
            'goldTxId' => (int) $gold_tx_id,
        ));

        if (empty($res['txHash'])) {
            throw new Exception('signer tidak mengembalikan txHash');
        }
        return $res['txHash'];
    }

    /**
     * @return array|null null jika transaksi belum ter-mine (signer balas 204).
     * @throws Exception bila signer merespons status HTTP selain 200/204.
     */
    public function get_receipt($hash) {
        $ch = curl_init($this->base . '/receipt?hash=' . urlencode($hash));
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_TIMEOUT        => 20,
        ));
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new Exception("signer /receipt tidak terjangkau: {$err}");
        }
        if ($code === 204) { return NULL; }
        if ($code !== 200) { throw new Exception("signer /receipt HTTP {$code}"); }

        return json_decode($body, TRUE);
    }

    private function _post($path, array $payload) {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_POST           => TRUE,
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 60,
        ));
        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new Exception("signer {$path} tidak terjangkau: {$err}");
        }
        if ($code !== 200) {
            throw new Exception("signer {$path} HTTP {$code}: {$body}");
        }
        return json_decode($body, TRUE);
    }
}
