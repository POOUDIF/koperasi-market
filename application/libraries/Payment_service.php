<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Aturan bisnis Koperasi Pay (§4) dan PIN transaksi.
 */
class Payment_service {

    private $CI;

    public function __construct() {
        $this->CI =& get_instance();
        $this->CI->load->model(array('Payment_model', 'User_model', 'User_profile_model', 'Notification_model'));
        $this->CI->load->library('Webhook_dispatcher');
    }

    /* --------------------------------------------------- API internal */

    public function member_summary($sub) {
        $u = $this->CI->User_model->find_by_sub($sub);
        if ($u === NULL) {
            return array('sub' => (string) $sub, 'linked' => FALSE, 'is_member' => FALSE, 'kyc_completed' => FALSE, 'status' => NULL);
        }
        return array(
            'sub'           => (string) $sub,
            'linked'        => TRUE,
            'is_member'     => $u['member_since'] !== NULL && $u['status'] === 'active',
            'member_since'  => $u['member_since'],
            'kyc_completed' => $this->CI->User_profile_model->find($u['id']) !== NULL,
            'status'        => $u['status'],
        );
    }

    public function create_intent($client_id, array $in, $idempotency_key) {
        $clients = (array) $this->CI->config->item('payment_clients');
        if ( ! isset($clients[$client_id])) {
            throw Api_exception::forbidden('client tidak terdaftar untuk Koperasi Pay');
        }

        if ( ! preg_match('/^[A-Za-z0-9._:-]{8,100}$/', (string) $idempotency_key)) {
            throw Api_exception::badRequest('header Idempotency-Key wajib (8-100 karakter)');
        }
        if (strpos($in['return_url'], $clients[$client_id]['return_url_prefix']) !== 0) {
            throw Api_exception::badRequest('return_url tidak diizinkan');
        }
        if (Money::gt($in['amount'], $this->CI->config->item('payment_max_amount'))) {
            throw Api_exception::badRequest('nominal melebihi batas per transaksi');
        }
        if ((string) $in['payer_sub'] === (string) $in['payee_sub']) {
            throw Api_exception::badRequest('pembeli dan penjual tidak boleh sama');
        }

        $payer = $this->CI->User_model->find_by_sub($in['payer_sub']);
        if ($payer === NULL || $payer['member_since'] === NULL || $payer['status'] !== 'active') {
            throw Api_exception::payerNotMember();
        }
        $payee = $this->CI->User_model->find_by_sub($in['payee_sub']);
        if ($payee === NULL || $payee['member_since'] === NULL || $payee['status'] !== 'active') {
            throw Api_exception::payeeNotMember();
        }

        $request_hash = hash('sha256', json_encode(array(
            $in['merchant_ref'], (string) $in['payer_sub'], (string) $in['payee_sub'], Money::norm($in['amount']),
            $in['description'], $in['return_url'],
        )));

        list($pi, $created) = $this->CI->Payment_model->create(array(
            'client_id'       => $client_id,
            'idempotency_key' => $idempotency_key,
            'request_hash'    => $request_hash,
            'merchant_ref'    => $in['merchant_ref'],
            'payer_user_id'   => $payer['id'],
            'payee_user_id'   => $payee['id'],
            'amount'          => Money::norm($in['amount']),
            'description'     => $in['description'],
            'return_url'      => $in['return_url'],
            'ttl'             => $this->CI->config->item('payment_intent_ttl'),
        ));

        return array($this->internal_view($pi), $created);
    }

    public function internal_view(array $pi) {
        return $this->CI->Payment_model->to_public($pi) + array(
            'payment_url' => $this->CI->config->item('app_url') . '/koperasi/pay/' . $pi['public_id'],
        );
    }

    public function settle($public_id, $client_id) {
        $escrow = $this->escrow_account_id();
        $payee_product = $this->CI->Payment_model->find_product_id_by_name($this->CI->config->item('payee_product_name'));
        if ($payee_product === NULL) {
            log_message('error', '[payment] produk simpanan penerima tidak ditemukan');
            throw Api_exception::server();
        }

        $id = $this->CI->Payment_model->settle($public_id, $client_id, $escrow, $payee_product);
        $this->CI->webhook_dispatcher->flush_intent($id);

        $pi = $this->CI->Payment_model->find_by_public_id($public_id);
        $this->notify((int) $pi['payee_user_id'], 'Dana penjualan diterima',
            'Dana penjualan marketplace sebesar ' . $this->rupiah($pi['amount']) . ' telah masuk ke Simpanan Sukarela Anda.');
        return $pi;
    }

    public function refund($public_id, $client_id) {
        $id = $this->CI->Payment_model->refund($public_id, $client_id, $this->escrow_account_id());
        $this->CI->webhook_dispatcher->flush_intent($id);

        $pi = $this->CI->Payment_model->find_by_public_id($public_id);
        $this->notify((int) $pi['payer_user_id'], 'Dana pembelian dikembalikan',
            'Pembayaran marketplace sebesar ' . $this->rupiah($pi['amount']) . ' telah dikembalikan ke rekening Anda.');
        return $pi;
    }

    public function cancel_by_client($public_id, $client_id) {
        $id = $this->CI->Payment_model->cancel($public_id, $client_id, NULL);
        $this->CI->webhook_dispatcher->flush_intent($id);
        return $this->CI->Payment_model->find_by_public_id($public_id);
    }

    /* ------------------------------------------------ anggota (browser) */

    public function view_for_payer($public_id, $user_id) {
        $pi = $this->CI->Payment_model->find_by_public_id($public_id);
        if ($pi === NULL || (int) $pi['payer_user_id'] !== (int) $user_id) {
            throw Api_exception::paymentNotFound();
        }
        $clients = (array) $this->CI->config->item('payment_clients');
        $pin = $this->CI->User_model->get_pin_state($user_id);

        return $this->CI->Payment_model->to_public($pi) + array(
            'merchant_name' => $clients[$pi['client_id']]['display_name'] ?? $pi['client_id'],
            'return_url'    => $this->return_url($pi),
            'accounts'      => $this->CI->Payment_model->spendable_accounts($user_id),
            'has_pin'       => $pin !== NULL && $pin['pin_hash'] !== NULL,
        );
    }

    public function confirm($public_id, $user_id, $account_id, $pin) {
        $this->verify_pin($user_id, $pin);

        $id = $this->CI->Payment_model->confirm($public_id, $user_id, $account_id, $this->escrow_account_id());
        $this->CI->webhook_dispatcher->flush_intent($id);

        $pi = $this->CI->Payment_model->find_by_public_id($public_id);
        $this->notify((int) $user_id, 'Pembayaran marketplace berhasil',
            'Pembayaran ' . $this->rupiah($pi['amount']) . ' untuk ' . $pi['description'] . ' berhasil. Dana ditahan koperasi sampai pesanan selesai.');

        return array('status' => $pi['status'], 'redirect_url' => $this->return_url($pi));
    }

    public function cancel_by_payer($public_id, $user_id) {
        $id = $this->CI->Payment_model->cancel($public_id, NULL, $user_id);
        $this->CI->webhook_dispatcher->flush_intent($id);
        $pi = $this->CI->Payment_model->find_by_public_id($public_id);
        return array('status' => $pi['status'], 'redirect_url' => $this->return_url($pi));
    }

    /* ------------------------------------------------------------- PIN */

    public function set_pin($user_id, $pin) {
        $pin = (string) $pin;
        if ( ! preg_match('/^\d{6}$/', $pin) || preg_match('/^(\d)\1{5}$/', $pin)
            || strpos('0123456789', $pin) !== FALSE || strpos('9876543210', $pin) !== FALSE) {
            throw Api_exception::weakPin();
        }
        $this->CI->User_model->set_pin($user_id, password_hash($pin, PASSWORD_BCRYPT,
            array('cost' => (int) $this->CI->config->item('bcrypt_cost'))));
    }

    public function verify_pin($user_id, $pin) {
        $state = $this->CI->User_model->get_pin_state($user_id);
        if ($state === NULL || $state['pin_hash'] === NULL) { throw Api_exception::pinNotSet(); }
        if ((int) $state['locked'] === 1)                    { throw Api_exception::pinLocked(); }

        if ( ! password_verify((string) $pin, $state['pin_hash'])) {
            $this->CI->User_model->register_pin_failure($user_id,
                $this->CI->config->item('pin_max_attempts'), $this->CI->config->item('pin_lock_minutes'));
            throw Api_exception::pinInvalid();
        }
        $this->CI->User_model->reset_pin_failures($user_id);
    }

    /* --------------------------------------------------------- internal */

    private function escrow_account_id() {
        $id = $this->CI->Payment_model->find_escrow_account_id(
            $this->CI->config->item('escrow_user_email'), $this->CI->config->item('escrow_product_name'));
        if ($id === NULL) {
            log_message('error', '[payment] rekening penampung belum dibuat — jalankan migrasi 007');
            throw Api_exception::server();
        }
        return $id;
    }

    private function return_url(array $pi) {
        return $pi['return_url'] . (strpos($pi['return_url'], '?') === FALSE ? '?' : '&')
            . 'payment_intent=' . rawurlencode($pi['public_id']) . '&status=' . rawurlencode($pi['status']);
    }

    private function notify($user_id, $title, $message) {
        try {
            $this->CI->Notification_model->insert($user_id, 'simpanan', $title, $message);
        } catch (Throwable $e) {
            log_message('error', '[notification] ' . $e->getMessage());
        }
    }

    private function rupiah($amount) {
        return 'Rp ' . number_format((float) $amount, 0, ',', '.');
    }
}
