<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pengirim webhook Koperasi Pay (outbox `webhook_deliveries`).
 *
 * Dicoba langsung setelah transaksi commit (timeout pendek); yang gagal
 * diulang `php index.php cli/webhooks run` (cron tiap menit) dengan backoff
 * eksponensial. Penerima WAJIB idempoten per event id — retry resmi bisa
 * mengirim event yang sama lebih dari sekali.
 */
class Webhook_dispatcher {

    const MAX_ATTEMPTS = 12;

    private $CI;

    public function __construct() {
        $this->CI =& get_instance();
        $this->CI->load->library(array('Http_client', 'Webhook_signature'));
    }

    /** Kirim event yang jatuh tempo untuk satu intent (setelah commit). Tidak pernah melempar. */
    public function flush_intent($intent_id) {
        try {
            $rows = $this->CI->db->query(
                "SELECT id, event_id, client_id, payload, attempts FROM webhook_deliveries
                  WHERE intent_id = ? AND delivered_at IS NULL AND failed_at IS NULL AND next_attempt_at <= NOW()
                  ORDER BY id", array((int) $intent_id))->result_array();
            $this->deliver($rows, 3);
        } catch (Throwable $e) {
            log_message('error', '[webhook] flush gagal: ' . $e->getMessage());
        }
    }

    /** @return array [terkirim, gagal] */
    public function run($limit = 100) {
        $rows = $this->CI->db->query(
            "SELECT id, event_id, client_id, payload, attempts FROM webhook_deliveries
              WHERE delivered_at IS NULL AND failed_at IS NULL AND next_attempt_at <= NOW()
              ORDER BY id LIMIT ?", array((int) $limit))->result_array();
        return $this->deliver($rows, 10);
    }

    private function deliver(array $rows, $timeout) {
        $clients = (array) $this->CI->config->item('payment_clients');
        $ok = 0; $fail = 0;

        foreach ($rows as $row) {
            $c = $clients[$row['client_id']] ?? NULL;
            if ($c === NULL || $c['webhook_url'] === '' || $c['webhook_secret'] === '') {
                $this->mark_failed($row, 'client/webhook tidak dikonfigurasi', TRUE);
                $fail++;
                continue;
            }

            $ts = (string) time();
            try {
                $r = $this->CI->http_client->request('POST', $c['webhook_url'], array(
                    'raw'     => $row['payload'],
                    'headers' => array(
                        'Content-Type: application/json',
                        'X-JDC-Event-Id: ' . $row['event_id'],
                        'X-JDC-Timestamp: ' . $ts,
                        'X-JDC-Signature: ' . $this->CI->webhook_signature->sign($c['webhook_secret'], $ts, $row['payload']),
                    ),
                    'timeout' => $timeout,
                ));
                $error = ($r['status'] >= 200 && $r['status'] < 300) ? NULL : 'HTTP ' . $r['status'] . ' ' . substr($r['body'], 0, 200);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }

            if ($error === NULL) {
                $this->CI->db->query("UPDATE webhook_deliveries SET delivered_at = NOW(), attempts = attempts + 1, last_error = NULL WHERE id = ?",
                    array($row['id']));
                $ok++;
            } else {
                $this->mark_failed($row, $error, ((int) $row['attempts'] + 1) >= self::MAX_ATTEMPTS);
                log_message('error', "[webhook] {$row['event_id']} gagal: {$error}");
                $fail++;
            }
        }
        return array($ok, $fail);
    }

    private function mark_failed(array $row, $error, $give_up) {
        $delay = min(21600, 30 * (1 << min(12, (int) $row['attempts'])));   // 30 dtk … maks 6 jam
        $this->CI->db->query(
            "UPDATE webhook_deliveries
                SET attempts = attempts + 1, last_error = ?, next_attempt_at = NOW() + INTERVAL ? SECOND,
                    failed_at = IF(?, NOW(), NULL)
              WHERE id = ?", array(substr($error, 0, 500), $delay, $give_up ? 1 : 0, $row['id']));
    }
}
