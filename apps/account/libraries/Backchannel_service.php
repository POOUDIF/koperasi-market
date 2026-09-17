<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * OpenID Connect Back-Channel Logout 1.0 — sisi IdP (§3.4).
 *
 * Pola outbox: job dicatat di DB lebih dulu, lalu langsung dicoba kirim
 * dengan timeout pendek. Yang gagal diulang cron (`cli/backchannel run`)
 * dengan backoff eksponensial. Jaring pengaman terakhir tetap ada di client:
 * refresh token yang sudah dicabut membuat sesi BFF mati ≤ 15 menit.
 */
class Backchannel_service {

    const MAX_ATTEMPTS = 10;
    const INLINE_TIMEOUT = 3;

    private $CI;

    public function __construct() {
        $this->CI =& get_instance();
        $this->CI->load->model(array('Session_model', 'Oauth_model', 'Audit_model'));
        $this->CI->load->library(array('Token_service', 'Http_client'));
    }

    public function notify_session($session_id, $sid, $user_id) {
        $ids = array();
        foreach ($this->CI->Session_model->clients_of($session_id) as $client_id) {
            $client = $this->CI->Oauth_model->find_client($client_id);
            if ($client === NULL || empty($client['backchannel_logout_uri'])) { continue; }
            $ids[] = $this->CI->Audit_model->enqueue_backchannel($client_id, $sid, $user_id);
        }
        if ( ! empty($ids)) {
            $this->deliver($this->CI->Audit_model->due_backchannel_jobs(count($ids), $ids), self::INLINE_TIMEOUT);
        }
    }

    /** Dipanggil cron. @return array [terkirim, gagal] */
    public function run($limit = 100) {
        return $this->deliver($this->CI->Audit_model->due_backchannel_jobs($limit), 10);
    }

    private function deliver(array $jobs, $timeout) {
        $ok = 0; $fail = 0;

        foreach ($jobs as $job) {
            $client = $this->CI->Oauth_model->find_client($job['client_id']);
            if ($client === NULL || empty($client['backchannel_logout_uri'])) {
                $this->CI->Audit_model->backchannel_failed($job['id'], $job['attempts'], 'client tidak aktif', TRUE);
                continue;
            }

            try {
                $r = $this->CI->http_client->request('POST', $client['backchannel_logout_uri'], array(
                    'form'    => array('logout_token' => $this->CI->token_service->logout_token($job['client_id'], $job['sub'], $job['sid'])),
                    'timeout' => $timeout,
                ));
                $error = ($r['status'] === 200 || $r['status'] === 204) ? NULL : 'HTTP ' . $r['status'] . ' ' . substr($r['body'], 0, 200);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }

            if ($error === NULL) {
                $this->CI->Audit_model->backchannel_delivered($job['id']);
                $ok++;
            } else {
                $give_up = ((int) $job['attempts'] + 1) >= self::MAX_ATTEMPTS;
                $this->CI->Audit_model->backchannel_failed($job['id'], $job['attempts'], $error, $give_up);
                log_message('error', "[backchannel] gagal ke {$job['client_id']} sid={$job['sid']}: {$error}");
                $fail++;
            }
        }
        return array($ok, $fail);
    }
}
