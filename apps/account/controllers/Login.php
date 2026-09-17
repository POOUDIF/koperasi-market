<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Login extends Web_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library('Auth_service');
    }

    /** GET /account/login?return=&reauth=1 */
    public function form() {
        $return = $this->safe_return($this->input->get('return'));
        $reauth = $this->input->get('reauth') === '1';
        $s      = $this->idp_session->current();

        if ($s !== NULL && ! $reauth) {
            return $this->redirect_to($return);
        }

        $this->render('login', array(
            'title'  => 'Masuk',
            'return' => $return,
            'reauth' => $reauth,
            'email'  => $reauth && $s !== NULL ? $s['user']['email'] : (string) $this->input->get('email'),
            'flash'  => $this->flash_message((string) $this->input->get('msg')),
            'error'  => $this->input->get('reason') === 'account_disabled'
                ? 'Akun Anda dinonaktifkan. Hubungi pengurus koperasi.' : NULL,
        ));
    }

    /** POST /account/login */
    public function submit() {
        $return   = $this->safe_return($this->input->post('return'));
        $reauth   = $this->input->post('reauth') === '1';
        $email    = trim((string) $this->input->post('email'));
        $remember = $this->input->post('remember') === '1';

        try {
            $this->ratelimit->check('login', 10, 60);
            if ($email === '' || (string) $this->input->post('password') === '') {
                throw Api_exception::badRequest('Email dan kata sandi wajib diisi.');
            }

            $user = $this->auth_service->login($email, $this->input->post('password'));

            if ($user['email_verified_at'] === NULL) {
                // Password sudah terbukti benar: aman mengungkap status verifikasi.
                $this->auth_service->send_verification($user);
                return $this->redirect_to($this->config->item('base_path') . '/verify-email?email='
                    . rawurlencode($user['email']) . '&return=' . rawurlencode($return) . '&remember=' . ($remember ? '1' : '0'));
            }

            $this->idp_session->login($user, $remember);
            return $this->redirect_to($return);

        } catch (Api_exception $e) {
            $this->render('login', array(
                'title'  => 'Masuk',
                'return' => $return,
                'reauth' => $reauth,
                'email'  => $email,
                'error'  => $e->getMessage(),
                'flash'  => NULL,
            ), $e->status >= 500 ? 500 : ($e->status === 429 ? 429 : 200));
        }
    }

    /** POST /account/logout — dari halaman Akun Saya. */
    public function logout() {
        $this->idp_session->logout('logout');
        return $this->redirect_to($this->config->item('base_path') . '/login?msg=logged_out');
    }
}
