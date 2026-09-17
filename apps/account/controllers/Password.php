<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Password extends Web_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library('Auth_service');
    }

    /** GET /account/forgot-password */
    public function forgot_form() {
        $this->render('forgot', array(
            'title' => 'Lupa Kata Sandi',
            'flash' => $this->flash_message((string) $this->input->get('msg')),
            'error' => NULL,
        ));
    }

    /** POST /account/forgot-password — selalu membalas generik (anti-enumerasi). */
    public function forgot_submit() {
        $email = trim((string) $this->input->post('email'));

        try {
            $this->ratelimit->check('forgot', 3, 60);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->auth_service->forgot_password($email);
            }
        } catch (Api_exception $e) {
            if ($e->status === 429) {
                return $this->render('forgot', array('title' => 'Lupa Kata Sandi', 'flash' => NULL, 'error' => $e->getMessage()), 429);
            }
            log_message('error', '[forgot] ' . $e->getMessage());
        }

        return $this->redirect_to($this->config->item('base_path') . '/forgot-password?msg=reset_sent');
    }

    /** GET /account/reset-password?token= */
    public function reset_form() {
        $token = (string) $this->input->get('token');
        $valid = $this->auth_service->reset_token_valid($token);

        $this->render('reset', array(
            'title' => 'Atur Ulang Kata Sandi',
            'token' => $valid ? $token : '',
            'error' => $valid ? NULL : 'Tautan reset kata sandi tidak valid atau sudah kedaluwarsa. Silakan minta tautan baru.',
        ), $valid ? 200 : 400);
    }

    /** POST /account/reset-password */
    public function reset_submit() {
        $token = (string) $this->input->post('token');

        try {
            $this->ratelimit->check('reset', 5, 60);
            if ((string) $this->input->post('password') !== (string) $this->input->post('password_confirm')) {
                throw Api_exception::badRequest('Konfirmasi kata sandi tidak sama.');
            }
            $this->auth_service->reset_password($token, (string) $this->input->post('password'));
            return $this->redirect_to($this->config->item('base_path') . '/login?msg=password_reset');

        } catch (Api_exception $e) {
            $this->render('reset', array(
                'title' => 'Atur Ulang Kata Sandi',
                'token' => $e->code_name === 'RESET_TOKEN_INVALID' ? '' : $token,
                'error' => $e->getMessage(),
            ), $e->status >= 500 ? 500 : 200);
        }
    }
}
