<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 *   php account.php cli/clients sync     tulis config/clients.php + secret dari .env ke DB
 *   php account.php cli/clients secret   cetak secret acak baru (untuk .env)
 */
class Clients extends CI_Controller {

    public function __construct() {
        parent::__construct();
        if ( ! is_cli()) { show_404(); }
        $this->load->model('Oauth_model');
        $this->load->config('clients');
    }

    public function sync() {
        $failed = FALSE;

        foreach ($this->config->item('oauth_clients') as $client_id => $c) {
            $secret = env($c['secret_env']);
            if ($secret === NULL || strlen($secret) < 32) {
                fwrite(STDERR, "LEWATI {$client_id}: {$c['secret_env']} kosong / kurang dari 32 karakter" . PHP_EOL);
                $failed = TRUE;
                continue;
            }
            $this->Oauth_model->upsert_client($client_id, $c, hash('sha256', $secret));
            echo "OK  {$client_id}" . PHP_EOL;
        }
        exit($failed ? 1 : 0);
    }

    public function secret() {
        echo random_token(32) . PHP_EOL;
    }
}
