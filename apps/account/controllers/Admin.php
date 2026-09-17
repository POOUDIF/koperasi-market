<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin platform: daftar akun & blokir/pulihkan secara global.
 * Role per layanan (pengurus koperasi, admin market) dikelola di layanan
 * masing-masing, bukan di sini (§3.7).
 */
class Admin extends Web_Controller {

    private $admin_session;

    public function __construct() {
        parent::__construct();
        $this->admin_session = $this->require_login();

        if ((int) $this->admin_session['user']['is_platform_admin'] !== 1) {
            $this->render_error(403, 'Akses ditolak', 'Halaman ini hanya untuk admin platform JDC.');
            $this->halt();
        }
    }

    /** GET /account/admin/users?q=&page= */
    public function users() {
        $q    = trim((string) $this->input->get('q'));
        $page = max(1, (int) $this->input->get('page'));
        $per  = (int) $this->config->item('page_size_default');

        $res = $this->User_model->search_paged($q, $per, ($page - 1) * $per);

        $this->render('admin_users', array(
            'title' => 'Admin — Akun',
            'layout' => 'app',
            'is_admin' => TRUE,
            'me'    => $this->admin_session['user'],
            'items' => $res['items'],
            'total' => $res['total'],
            'page'  => $page,
            'pages' => max(1, (int) ceil($res['total'] / $per)),
            'q'     => $q,
            'flash' => $this->flash_message((string) $this->input->get('msg')),
        ));
    }

    /** POST /account/admin/users/:id/status  status=active|banned */
    public function set_status($id) {
        $status = (string) $this->input->post('status');
        $target = $this->User_model->find_by_id((int) $id);

        if ($target === NULL || ! in_array($status, array('active', 'banned'), TRUE)) {
            return $this->render_error(400, 'Permintaan tidak valid', 'Akun atau status tidak dikenal.');
        }
        if ((int) $target['id'] === (int) $this->admin_session['user_id']) {
            return $this->render_error(400, 'Permintaan tidak valid', 'Anda tidak dapat mengubah status akun sendiri.');
        }

        $this->User_model->set_status($target['id'], $status);
        if ($status === 'banned') {
            // Blokir berlaku seketika di semua layanan lewat back-channel logout.
            $this->idp_session->revoke_all_for_user($target['id'], 'banned');
        }
        $this->Audit_model->log('user_status_changed', array(
            'user_id' => $target['id'],
            'meta'    => array('status' => $status, 'by' => (int) $this->admin_session['user_id']),
        ));

        return $this->redirect_to($this->config->item('base_path') . '/admin/users?msg=status_saved&q=' . rawurlencode((string) $this->input->post('q')));
    }
}
