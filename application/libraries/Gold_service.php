<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Logika bisnis emas digital (§15).
 */
class Gold_service {

    private $CI;

    public function __construct() {
        $this->CI =& get_instance();
        $this->CI->load->model('Gold_model');
    }

    /**
     * FLOW-18 Harga emas dengan pola cache-aside (§15.1).
     * Kegagalan Redis tidak pernah menggagalkan request — hanya melewati cache.
     */
    public function get_current_price() {
        $key = $this->CI->config->item('gold_price_cache_key');

        try {
            $cached = $this->CI->redisx->get($key);
            if ($cached !== NULL) {
                $p = json_decode($cached, TRUE);
                if (json_last_error() === JSON_ERROR_NONE && isset($p['buy_price_per_gram'])) {
                    return $p;
                }
                log_message('warning', '[gold] cache korupsi, fallback ke DB');
            }
        } catch (Throwable $e) {
            log_message('warning', '[gold] Redis get gagal (non-fatal): ' . $e->getMessage());
        }

        $row = $this->CI->Gold_model->latest_price();
        if ($row === NULL) { throw Api_exception::goldPriceUnavailable(); }

        try {
            $this->CI->redisx->setex($key,
                (int) $this->CI->config->item('gold_price_cache_ttl'), json_encode($row));
        } catch (Throwable $e) {
            log_message('warning', '[gold] Redis setex gagal (non-fatal): ' . $e->getMessage());
        }

        return $row;
    }

    /** Perbaikan CACAT-08: perbarui harga + invalidasi cache. */
    public function update_price($buy, $sell) {
        $price = $this->CI->Gold_model->insert_price($buy, $sell);
        $this->invalidate_price_cache();
        return $price;
    }

    public function invalidate_price_cache() {
        try {
            $this->CI->redisx->del($this->CI->config->item('gold_price_cache_key'));
        } catch (Throwable $e) {
            log_message('warning', '[gold] gagal invalidasi cache harga: ' . $e->getMessage());
        }
    }

    /**
     * FLOW-19 Beli emas (§15.2).
     * Saldo didebet SEKARANG; token di-mint belakangan oleh worker. Kalau mint
     * gagal, uangnya dikembalikan lewat Gold_model::refund_failed_transaction.
     */
    public function buy($user_id, array $in) {
        $gram = Money::norm($in['gram_amount']);
        $this->assert_within_limit($gram);

        $price = $this->get_current_price();
        $per   = Money::norm($price['buy_price_per_gram']);
        $total = Money::mul($gram, $per);

        $gold_tx_id = $this->CI->Gold_model->buy_with_debit(
            $user_id, (int) $in['savings_account_id'], $gram, $per, $total);

        // Dorong ke antrian worker — non-fatal: transaksi sudah aman di DB dan
        // worker punya recovery yang me-requeue semua status 'pending'.
        try {
            $this->CI->redisx->rpush($this->CI->config->item('gold_mint_queue_key'), $gold_tx_id);
        } catch (Throwable $e) {
            log_message('error', '[gold] RPush gagal untuk ID=' . $gold_tx_id . ': ' . $e->getMessage()
                . ' - transaksi akan diambil oleh recovery worker');
        }

        return $this->CI->Gold_model->find_by_id($gold_tx_id);
    }

    /**
     * FLOW-20 Jual emas (§15.3). Murni off-chain, langsung `success`.
     * Validasi kepemilikan emas ada di dalam transaction (perbaikan CACAT-01).
     */
    public function sell($user_id, array $in) {
        $gram = Money::norm($in['gram_amount']);
        $this->assert_within_limit($gram);

        $price = $this->get_current_price();
        $per   = Money::norm($price['sell_price_per_gram']);
        $total = Money::mul($gram, $per);

        $gold_tx_id = $this->CI->Gold_model->sell_with_credit(
            $user_id, (int) $in['savings_account_id'], $gram, $per, $total);

        return $this->CI->Gold_model->find_by_id($gold_tx_id);
    }

    public function holding($user_id) {
        return $this->CI->Gold_model->net_holding($user_id);
    }

    /**
     * Batas per transaksi. Di versi Go, handler /gold/buy tidak menangani
     * error ini sehingga pembelian >100 gram mengembalikan 500 alih-alih 400
     * (CACAT-07); Api_exception memberi 400 di kedua endpoint.
     */
    private function assert_within_limit($gram) {
        $max = (string) $this->CI->config->item('gold_max_gram_per_tx');
        if (Money::gt($gram, $max)) {
            throw Api_exception::goldLimitExceeded($max);
        }
    }
}
