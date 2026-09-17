<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * API internal Koperasi Pay (scope koperasi.payments.write) — §4.2.
 *
 *   POST /internal/payment-intents                (header Idempotency-Key wajib)
 *   GET  /internal/payment-intents/:id
 *   POST /internal/payment-intents/:id/settle
 *   POST /internal/payment-intents/:id/refund
 *   POST /internal/payment-intents/:id/cancel
 */
class Payment_intents extends Internal_Controller {

    public function __construct() {
        parent::__construct();
        $this->require_scope('koperasi.payments.write');
        $this->load->library('Payment_service');
        $this->load->model('Payment_model');
    }

    public function create() {
        $this->run(function () {
            $in = $this->validator->check($this->body, array(
                'merchant_ref' => array('required', 'max:100'),
                'payer_sub'    => array('required', 'max:64'),
                'payee_sub'    => array('required', 'max:64'),
                'amount'       => array('required', 'num_gt:0'),
                'description'  => array('required', 'max:255'),
                'return_url'   => array('required', 'max:500'),
            ));
            // Nominal wajib dikirim sebagai string desimal agar tidak melewati float.
            if ( ! is_string($this->body['amount'])) {
                throw Api_exception::badRequest("field 'amount' wajib string desimal, mis. \"150000.00\"");
            }

            list($view, $created) = $this->payment_service->create_intent(
                $this->client_id, $in, (string) $this->input->get_request_header('Idempotency-Key', FALSE));

            return $this->ok($view, $created ? 201 : 200);
        });
    }

    public function show($public_id) {
        $this->run(function () use ($public_id) {
            $pi = $this->Payment_model->find_by_public_id($public_id);
            if ($pi === NULL || $pi['client_id'] !== $this->client_id) {
                throw Api_exception::paymentNotFound();
            }
            return $this->ok($this->payment_service->internal_view($pi), 200);
        });
    }

    public function settle($public_id) {
        $this->run(function () use ($public_id) {
            return $this->ok($this->payment_service->internal_view(
                $this->payment_service->settle($public_id, $this->client_id)), 200);
        });
    }

    public function refund($public_id) {
        $this->run(function () use ($public_id) {
            return $this->ok($this->payment_service->internal_view(
                $this->payment_service->refund($public_id, $this->client_id)), 200);
        });
    }

    public function cancel($public_id) {
        $this->run(function () use ($public_id) {
            return $this->ok($this->payment_service->internal_view(
                $this->payment_service->cancel_by_client($public_id, $this->client_id)), 200);
        });
    }
}
