<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * "Akun Saya" — profil, kata sandi, sesi aktif, dan peluncur layanan JDC.
 */
class Home extends Web_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Session_model');
    }

    /** GET /account */
    public function index() {
        $s = $this->require_login();
        $this->page($s);
    }

    /** POST /account/profile */
    public function update_profile() {
        $s = $this->require_login();
        $name = trim((string) $this->input->post('name'));

        if (mb_strlen($name) < 3 || mb_strlen($name) > 150) {
            return $this->page($s, 'Nama lengkap harus 3–150 karakter.');
        }
        $this->User_model->update_name($s['user_id'], $name);
        $this->Audit_model->log('profile_updated', array('user_id' => $s['user_id']));
        return $this->redirect_to($this->config->item('base_path') . '?msg=profile_saved');
    }

    /** POST /account/password */
    public function change_password() {
        $s = $this->require_login();
        $this->load->library('Auth_service');

        try {
            $this->ratelimit->check('change_password', 5, 60);
            if ((string) $this->input->post('password') !== (string) $this->input->post('password_confirm')) {
                throw Api_exception::badRequest('Konfirmasi kata sandi baru tidak sama.');
            }
            $this->auth_service->change_password($s['user'], (string) $this->input->post('current_password'),
                (string) $this->input->post('password'), $s['id']);
            return $this->redirect_to($this->config->item('base_path') . '?msg=password_changed');
        } catch (Api_exception $e) {
            return $this->page($s, $e->getMessage());
        }
    }

    /** POST /account/sessions/revoke — keluarkan satu sesi perangkat lain. */
    public function revoke_session() {
        $s   = $this->require_login();
        $sid = (string) $this->input->post('sid');

        foreach ($this->Session_model->active_for_user($s['user_id']) as $row) {
            if (hash_equals($row['sid'], $sid)) {
                if ((int) $row['id'] === (int) $s['id']) {
                    $this->idp_session->logout('logout');
                    return $this->redirect_to($this->config->item('base_path') . '/login?msg=logged_out');
                }
                $this->idp_session->revoke(array('id' => $row['id'], 'sid' => $row['sid'], 'user_id' => $s['user_id']), 'revoked_by_user');
                break;
            }
        }
        return $this->redirect_to($this->config->item('base_path') . '?msg=session_revoked');
    }

    private function page(array $s, $error = NULL) {
        $this->render('home', array(
            'title'    => 'Akun Saya',
            'layout'   => 'app',
            'is_admin' => (int) $s['user']['is_platform_admin'] === 1,
            'user'     => $s['user'],
            'session'  => $s,
            'sessions' => $this->Session_model->active_for_user($s['user_id']),
            'services' => $this->config->item('services'),
            'flash'    => $this->flash_message((string) $this->input->get('msg')),
            'error'    => $error,
        ), $error === NULL ? 200 : 400);
    }
}
