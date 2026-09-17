<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Checkout extends Customer_Controller {

    /**
     * POST /market/api/v1/checkout
     * {store_id, recipient_name, recipient_phone, shipping_address, buyer_note?}
     * → {order, payment_url}; frontend mengarahkan pembeli ke payment_url (domain koperasi).
     */
    public function create() {
        $this->run(function () {
            $this->ratelimit->check('checkout', 5, 10);

            $in = $this->validator->check($this->body, array(
                'store_id'         => array('required', 'int_gt:0'),
                'recipient_name'   => array('required', 'min:3', 'max:150'),
                'recipient_phone'  => array('required', 'min:10', 'max:20'),
                'shipping_address' => array('required', 'min:10', 'max:1000'),
                'buyer_note'       => array('max:255'),
            ));
            if ( ! preg_match('/^\+?[0-9]{10,15}$/', $in['recipient_phone'])) {
                throw Api_exception::badRequest('nomor telepon penerima tidak valid');
            }

            $this->load->library('Order_service');
            return $this->ok($this->order_service->checkout($this->customer, $in['store_id'], $in), 201);
        });
    }
}
