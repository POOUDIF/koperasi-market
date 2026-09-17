<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * POST /market/api/v1/webhooks/koperasi — event Koperasi Pay.
 *
 * Tanda tangan HMAC + timestamp diverifikasi terhadap body MENTAH, lalu
 * event dideduplikasi per id. Balasan 2xx = event diterima (koperasi berhenti
 * mengulang); 5xx = koperasi akan mengirim ulang.
 */
class Webhooks extends API_Controller {

    public function koperasi() {
        $raw = (string) file_get_contents('php://input');

        $this->load->library('Webhook_signature');
        $valid = $this->webhook_signature->verify(
            $this->config->item('koperasi_webhook_secret'),
            (string) $this->input->get_request_header('X-JDC-Timestamp', FALSE),
            (string) $this->input->get_request_header('X-JDC-Signature', FALSE),
            $raw);
        if ( ! $valid) {
            log_message('error', '[webhook] tanda tangan tidak valid');
            return $this->ok(array('error' => 'invalid signature'), 401);
        }

        $event = json_decode($raw, TRUE);
        if ( ! is_array($event) || empty($event['id']) || empty($event['type'])) {
            return $this->ok(array('error' => 'payload tidak valid'), 400);
        }

        $this->run(function () use ($event) {
            $this->load->model('Order_model');
            if ( ! $this->Order_model->record_webhook_event((string) $event['id'], (string) $event['type'])) {
                return $this->ok(array('status' => 'duplicate'), 200);
            }

            $this->load->library('Order_service');
            try {
                $this->order_service->handle_webhook($event);
            } catch (Throwable $e) {
                // Lepas penanda dedup agar retry berikutnya diproses ulang.
                $this->db->query("DELETE FROM webhook_events WHERE event_id = ?", array((string) $event['id']));
                throw $e;
            }
            return $this->ok(array('status' => 'ok'), 200);
        });
    }
}
