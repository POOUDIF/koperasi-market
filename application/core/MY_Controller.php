<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pengganti rantai middleware Gin (§10.3).
 *
 * Tiga kelas bertingkat yang meniru grup route persis:
 *   API_Controller   = gin.New() + Recovery + Logger + parser JSON
 *   Auth_Controller  = + RequireAuth + RequireActiveUserDB
 *   Admin_Controller = + RequireRole
 */
class API_Controller extends CI_Controller {

    /** @var array payload JSON hasil decode */
    protected $body = array();

    /* Identitas pemanggil, terisi setelah require_member() lolos. */
    protected $user_id;
    protected $user_email;
    protected $raw_token;

    public function __construct() {
        parent::__construct();

        // Sumber waktu tunggal ada di DB (§8.5). READ COMMITTED menyamai
        // sql.LevelReadCommitted yang dipakai seluruh transaksi Go (§9.4).
        $this->db->query("SET SESSION time_zone = '+07:00', SESSION transaction_isolation = 'READ-COMMITTED'");

        $this->_parse_json_body();
    }

    private function _parse_json_body() {
        $method = $this->input->method(TRUE);
        if ( ! in_array($method, array('POST', 'PUT', 'PATCH', 'DELETE'), TRUE)) {
            return;
        }

        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === FALSE) { return; }

        $decoded = json_decode($raw, TRUE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->fail(Api_exception::badRequest('body bukan JSON yang valid'));
        }
        $this->body = is_array($decoded) ? $decoded : array();
    }

    protected function ok($data, $status = 200) {
        return $this->api_response->send($data, $status);
    }

    /** Kirim response error dan HENTIKAN eksekusi — meniru c.AbortWithStatusJSON. */
    protected function fail(Api_exception $e) {
        $this->api_response->send(array(
            'error' => $e->getMessage(),
            'code'  => $e->code_name,
        ), $e->status);
        $this->output->_display();
        exit;
    }

    /**
     * Bungkus aksi controller: sentinel error → status yang benar,
     * error tak dikenal → 500 generik + log (pola handler Go).
     */
    protected function run(callable $fn) {
        try {
            return $fn();
        } catch (Api_exception $e) {
            $this->fail($e);
        } catch (Throwable $t) {
            log_message('error', '[api] ' . get_class($t) . ': ' . $t->getMessage()
                . ' @ ' . $t->getFile() . ':' . $t->getLine());
            $this->fail(Api_exception::server());
        }
    }

    /** Parse :id dari URI segment — meniru strconv.ParseInt + cek <= 0. */
    protected function param_id($raw, $label = 'id') {
        if ( ! ctype_digit((string) $raw) || (int) $raw <= 0) {
            throw Api_exception::badRequest("parameter {$label} tidak valid");
        }
        return (int) $raw;
    }

    /** Paginasi seragam untuk endpoint daftar — perbaikan CACAT-09. */
    protected function paging() {
        $max     = (int) $this->config->item('page_size_max');
        $default = (int) $this->config->item('page_size_default');

        $page = max(1, (int) $this->input->get('page'));
        $per  = (int) $this->input->get('per_page');
        $per  = ($per <= 0) ? $default : min($max, $per);

        return array('page' => $page, 'per_page' => $per, 'offset' => ($page - 1) * $per);
    }

    /**
     * Guard anggota yang bisa dipanggil PER METHOD, bukan lewat pewarisan.
     *
     * Dibutuhkan controller yang mencampur endpoint publik dan terproteksi —
     * Gold punya /gold/price (publik) bersama /gold/buy dan /gold/sell
     * (butuh JWT). Aman dipanggil dua kali; guard-nya idempoten.
     */
    protected function require_member() {
        if ($this->user_id !== NULL) { return; }

        $this->load->model('User_model');
        $this->_require_auth();
        $this->_require_active_user();
    }

    /* --- Implementasi guard; dipakai require_member() dan Auth_Controller --- */

    /** Langkah 1-4 middleware.RequireAuth. */
    protected function _require_auth() {
        $header = $this->input->get_request_header('Authorization', TRUE);

        if (empty($header)) {
            $this->fail(Api_exception::unauthorized('header Authorization tidak ada'));
        }

        $parts = explode(' ', $header, 2);
        if (count($parts) !== 2 || strcasecmp($parts[0], 'Bearer') !== 0 || trim($parts[1]) === '') {
            $this->fail(Api_exception::unauthorized("format Authorization harus 'Bearer <token>'"));
        }
        $token = trim($parts[1]);

        // Blocklist logout — diperiksa SEBELUM verifikasi signature, sama seperti Go.
        try {
            if ($this->redisx->exists($this->jwt_service->revoke_key($token))) {
                $this->fail(Api_exception::unauthorized('sesi telah diakhiri, silakan login kembali'));
            }
        } catch (Api_exception $e) {
            throw $e;
        } catch (Throwable $e) {
            // fail-open seperti kode Go: err != nil → lanjut verifikasi token.
            log_message('error', '[auth] cek blocklist gagal: ' . $e->getMessage());
        }

        $claims = $this->jwt_service->verify($token);
        if ($claims === NULL || empty($claims['user_id'])) {
            $this->fail(Api_exception::unauthorized('token tidak valid atau sudah kadaluarsa'));
        }

        $this->raw_token  = $token;
        $this->user_id    = (int) $claims['user_id'];
        $this->user_email = $claims['email'] ?? '';
    }

    /** Padanan RequireActiveUserDB: satu query ringan per request. */
    protected function _require_active_user() {
        $status = $this->User_model->get_status($this->user_id);

        if ($status === NULL) {
            $this->fail(Api_exception::unauthorized('akun tidak ditemukan, silakan login kembali'));
        }
        if ($status !== 'active') {
            $this->fail(Api_exception::forbidden('akun tidak aktif atau diblokir, hubungi admin koperasi'));
        }
    }
}

/**
 * Padanan RequireAuth + RequireActiveUserDB.
 */
class Auth_Controller extends API_Controller {

    public function __construct() {
        parent::__construct();
        $this->require_member();
    }
}

/**
 * Padanan RequireAuth + RequireRole.
 *
 * Berbeda dari Go, pemeriksaan status akun TETAP jalan di sini karena mewarisi
 * Auth_Controller. Ini perbaikan disengaja atas CACAT-03: di versi Go, admin
 * ber-status `banned` masih bisa meng-approve pembiayaan.
 */
class Admin_Controller extends Auth_Controller {

    protected $role;

    public function __construct() {
        parent::__construct();

        $this->role = $this->User_model->get_role($this->user_id);

        if ($this->role === NULL) {
            $this->fail(Api_exception::unauthorized('akun tidak ditemukan, silakan login kembali'));
        }
        if ( ! in_array($this->role, (array) $this->config->item('roles_admin'), TRUE)) {
            $this->fail(Api_exception::forbidden());
        }
    }
}
