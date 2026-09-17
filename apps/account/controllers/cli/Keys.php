<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 *   php account.php cli/keys generate   buat kunci RS256 baru (jadi kunci aktif)
 *   php account.php cli/keys list       tampilkan kid yang dipublikasikan di JWKS
 *
 * Rotasi: generate → tunggu > masa berlaku token terpanjang (id/access token
 * 15 menit; logout_token 2 menit) → hapus file PEM lama dari OIDC_KEYS_DIR.
 */
class Keys extends CI_Controller {

    public function __construct() {
        parent::__construct();
        if ( ! is_cli()) { show_404(); }
        $this->load->library('Token_service');
    }

    public function generate() {
        $path = $this->token_service->generate_key();
        echo "kunci baru: {$path}" . PHP_EOL;
        $this->list();
    }

    public function list() {
        try {
            $keys = $this->token_service->jwks()['keys'];
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            exit(1);
        }
        foreach ($keys as $i => $k) {
            echo ($i === count($keys) - 1 ? '* ' : '  ') . $k['kid'] . PHP_EOL;
        }
        echo '(* = kunci aktif penandatangan)' . PHP_EOL;
    }
}
