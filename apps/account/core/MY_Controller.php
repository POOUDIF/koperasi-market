<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Basis semua controller JDC Account.
 */
class Base_Controller extends CI_Controller {

    public function __construct() {
        parent::__construct();
        $this->db->query("SET SESSION time_zone = '+07:00', SESSION transaction_isolation = 'READ-COMMITTED'");
    }

    protected function redirect_to($url, $status = 302) {
        $this->output
            ->set_status_header($status, Api_response::REASONS[$status] ?? 'Status')
            ->set_header('Location: ' . $url)
            ->set_header('Cache-Control: no-store')
            ->set_header('Referrer-Policy: no-referrer');
    }

    protected function json($data, $status = 200) {
        $this->output
            ->set_status_header($status, Api_response::REASONS[$status] ?? 'Status')
            ->set_content_type('application/json', 'utf-8')
            ->set_header('Cache-Control: no-store')
            ->set_header('Pragma: no-cache')
            ->set_header('X-Content-Type-Options: nosniff')
            ->set_output(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Render halaman dengan layout. Header keamanan ketat karena halaman IdP
     * adalah target phishing/clickjacking paling berharga di sistem ini.
     */
    protected function render($view, array $data = array(), $status = 200) {
        $this->load->library('Csrf');
        $data['csrf']      = $this->csrf;
        $data['base']      = $this->config->item('base_path');
        $data['app_url']   = $this->config->item('app_url');
        $data['title']     = $data['title'] ?? 'Akun JDC';
        $data['content']   = $this->load->view($view, $data, TRUE);

        $this->output
            ->set_status_header($status, Api_response::REASONS[$status] ?? 'Status')
            ->set_content_type('text/html', 'utf-8')
            ->set_header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'")
            ->set_header('X-Frame-Options: DENY')
            ->set_header('X-Content-Type-Options: nosniff')
            ->set_header('Referrer-Policy: no-referrer')
            ->set_header('Cache-Control: no-store')
            ->set_header('Permissions-Policy: camera=(), microphone=(), geolocation=()')
            ->set_output($this->load->view('layout', $data, TRUE));

        if ($this->config->item('cookie_secure')) {
            $this->output->set_header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    protected function render_error($status, $title, $message) {
        $this->render('error', array('title' => $title, 'heading' => $title, 'message' => $message), $status);
    }

    /** Kirim output sekarang dan hentikan request (dipakai di constructor). */
    protected function halt() {
        $this->output->_display();
        exit;
    }

    /** return hanya boleh path di bawah /account (cegah open redirect). */
    protected function safe_return($value) {
        $base = $this->config->item('base_path');
        return Request_guard::safe_return_to($value, $base, $base);
    }

    /** Pesan status via kode tetap di query string — tidak pernah memantulkan teks bebas. */
    protected function flash_message($code) {
        $messages = array(
            'verified'         => array('success', 'Email berhasil diverifikasi.'),
            'otp_sent'         => array('info', 'Jika email terdaftar dan belum diverifikasi, kode baru telah dikirim.'),
            'reset_sent'       => array('info', 'Jika email terdaftar, tautan atur ulang kata sandi telah dikirim.'),
            'password_reset'   => array('success', 'Kata sandi berhasil diubah. Silakan masuk dengan kata sandi baru.'),
            'password_changed' => array('success', 'Kata sandi berhasil diubah. Sesi di perangkat lain telah dikeluarkan.'),
            'profile_saved'    => array('success', 'Profil berhasil disimpan.'),
            'session_revoked'  => array('success', 'Sesi berhasil dikeluarkan.'),
            'logged_out'       => array('success', 'Anda telah keluar dari semua layanan JDC.'),
            'status_saved'     => array('success', 'Status akun berhasil diperbarui.'),
        );
        return isset($messages[$code]) ? $messages[$code] : NULL;
    }
}

/**
 * Halaman HTML ber-form: CSRF wajib untuk setiap POST.
 */
class Web_Controller extends Base_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library(array('Csrf', 'Idp_session'));

        if ($this->input->method(TRUE) === 'POST' && ! $this->csrf->valid()) {
            $this->render_error(403, 'Permintaan ditolak',
                'Formulir kedaluwarsa atau berasal dari situs lain. Muat ulang halaman lalu coba lagi.');
            $this->halt();
        }
    }

    /** @return array sesi aktif; bila tidak ada, redirect ke login lalu berhenti. */
    protected function require_login() {
        $s = $this->idp_session->current();
        if ($s === NULL) {
            $this->redirect_to($this->config->item('base_path') . '/login?return='
                . rawurlencode($_SERVER['REQUEST_URI'] ?? $this->config->item('base_path')));
            $this->halt();
        }
        return $s;
    }
}
