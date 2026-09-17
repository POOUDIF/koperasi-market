<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Register extends Web_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library('Auth_service');
    }

    /** GET /account/register */
    public function form() {
        $return = $this->safe_return($this->input->get('return'));
        if ($this->idp_session->current() !== NULL) {
            return $this->redirect_to($return);
        }
        $this->render('register', array('title' => 'Daftar Akun', 'return' => $return, 'old' => array(), 'error' => NULL));
    }

    /** POST /account/register */
    public function submit() {
        $return = $this->safe_return($this->input->post('return'));
        $old = array(
            'name'  => trim((string) $this->input->post('name')),
            'email' => trim((string) $this->input->post('email')),
        );

        try {
            $this->ratelimit->check('register', 5, 60);

            $in = $this->validator->check(array(
                'name'     => $old['name'],
                'email'    => $old['email'],
                'password' => (string) $this->input->post('password'),
            ), array(
                'name'     => array('required', 'min:3', 'max:150'),
                'email'    => array('required', 'email', 'max:255'),
                'password' => array('required'),
            ));
            if ((string) $this->input->post('password') !== (string) $this->input->post('password_confirm')) {
                throw Api_exception::badRequest('Konfirmasi kata sandi tidak sama.');
            }
            if ($this->input->post('agree') !== '1') {
                throw Api_exception::badRequest('Anda harus menyetujui syarat & ketentuan serta kebijakan privasi.');
            }

            $user = $this->auth_service->register($in['name'], $in['email'], (string) $this->input->post('password'));

            return $this->redirect_to($this->config->item('base_path') . '/verify-email?email='
                . rawurlencode($user['email']) . '&return=' . rawurlencode($return));

        } catch (Api_exception $e) {
            $this->render('register', array(
                'title'  => 'Daftar Akun',
                'return' => $return,
                'old'    => $old,
                'error'  => $this->friendly($e),
            ), $e->status >= 500 ? 500 : 200);
        }
    }

    /** GET /account/verify-email?email=&return= */
    public function verify_form() {
        $this->render('verify', array(
            'title'    => 'Verifikasi Email',
            'email'    => (string) $this->input->get('email'),
            'return'   => $this->safe_return($this->input->get('return')),
            'remember' => $this->input->get('remember') === '1',
            'flash'    => $this->flash_message((string) $this->input->get('msg')),
            'error'    => NULL,
        ));
    }

    /** POST /account/verify-email — OTP benar = langsung masuk. */
    public function verify_submit() {
        $email    = trim((string) $this->input->post('email'));
        $return   = $this->safe_return($this->input->post('return'));
        $remember = $this->input->post('remember') === '1';

        try {
            $this->ratelimit->check('verify', 10, 60);
            $user = $this->auth_service->verify_email($email, trim((string) $this->input->post('otp')));
            $this->idp_session->login($user, $remember);
            return $this->redirect_to($return);

        } catch (Api_exception $e) {
            $this->render('verify', array(
                'title'    => 'Verifikasi Email',
                'email'    => $email,
                'return'   => $return,
                'remember' => $remember,
                'flash'    => NULL,
                'error'    => $e->getMessage(),
            ), $e->status >= 500 ? 500 : 200);
        }
    }

    /** POST /account/resend-otp — balasan selalu generik. */
    public function resend() {
        $email  = trim((string) $this->input->post('email'));
        $return = $this->safe_return($this->input->post('return'));

        try {
            $this->ratelimit->check('resend_otp', 3, 60);
            $this->auth_service->resend_verification($email);
        } catch (Api_exception $e) {
            if ($e->status === 429) {
                return $this->render('verify', array(
                    'title' => 'Verifikasi Email', 'email' => $email, 'return' => $return,
                    'remember' => FALSE, 'flash' => NULL, 'error' => $e->getMessage(),
                ), 429);
            }
        }

        return $this->redirect_to($this->config->item('base_path') . '/verify-email?email='
            . rawurlencode($email) . '&return=' . rawurlencode($return) . '&msg=otp_sent');
    }

    private function friendly(Api_exception $e) {
        // Pesan Validator bawaan berformat "field 'x' ..." — terjemahkan ke label form.
        return str_replace(
            array("field 'name'", "field 'email'", "field 'password'"),
            array('Nama lengkap', 'Email', 'Kata sandi'),
            $e->getMessage());
    }
}
