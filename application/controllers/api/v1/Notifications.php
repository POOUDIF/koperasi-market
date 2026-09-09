<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Notifikasi anggota — fitur baru di luar blueprint 24 endpoint.
 * Diisi otomatis oleh Saving_service/Financing_service saat admin mereview
 * permohonan; di sini anggota hanya membaca dan menandai dibaca.
 */
class Notifications extends Auth_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Notification_model');
    }

    /** GET /api/v1/notifications */
    public function index() {
        $this->run(function () {
            $pg = $this->paging();

            return $this->ok(array(
                'notifications' => $this->Notification_model->get_by_user_paged(
                    $this->user_id, $pg['per_page'], $pg['offset']),
                'page'         => $pg['page'],
                'per_page'     => $pg['per_page'],
                'total'        => $this->Notification_model->count_by_user($this->user_id),
                'unread_count' => $this->Notification_model->count_unread($this->user_id),
            ), 200);
        });
    }

    /** GET /api/v1/notifications/unread-count — dipakai lonceng di header, dipoll berkala. */
    public function unread_count() {
        $this->run(function () {
            return $this->ok(array(
                'unread_count' => $this->Notification_model->count_unread($this->user_id),
            ), 200);
        });
    }

    /** PUT /api/v1/notifications/:id/read */
    public function mark_read($id = NULL) {
        $this->run(function () use ($id) {
            $notification_id = $this->param_id($id, 'notification_id');

            $this->Notification_model->mark_read($this->user_id, $notification_id);

            return $this->ok(array('message' => 'notifikasi ditandai dibaca'), 200);
        });
    }

    /** PUT /api/v1/notifications/read-all */
    public function mark_all_read() {
        $this->run(function () {
            $count = $this->Notification_model->mark_all_read($this->user_id);

            return $this->ok(array(
                'message' => 'seluruh notifikasi ditandai dibaca',
                'updated' => $count,
            ), 200);
        });
    }
}
