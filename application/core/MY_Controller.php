<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pengganti rantai middleware Gin (§10.3), diperluas untuk SSO.
 *
 *   API_Controller      = gin.New() + Recovery + Logger + parser JSON
 *   Auth_Controller     = + autentikasi (sesi SSO / Bearer lama) + akun aktif + keanggotaan
 *   Admin_Controller    = + RequireRole
 *   Internal_Controller = API antar layanan, token client_credentials dari IdP (§4.1)
 *
 * Kontrak untuk controller turunan TIDAK berubah: identitas pemanggil ada di
 * $this->user_id — itulah sebabnya migrasi ke SSO tidak menyentuh logika
 * simpanan/pembiayaan/emas sama sekali (DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md §1).
 */
class API_Controller extends CI_Controller {

    /** @var array payload JSON hasil decode */
    protected $body = array();

    /* Identitas pemanggil, terisi setelah require_member() lolos. */
    protected $user_id;
    protected $user_email;
    protected $raw_token;
    /** Unix time saat user terakhir memasukkan kredensial di IdP (untuk step-up). */
    protected $auth_time;

    /**
     * Endpoint yang boleh dipakai akun JDC yang BELUM aktif sebagai anggota
     * koperasi (profil, KYC, aktivasi keanggotaan, notifikasi). Default FALSE.
     */
    protected $allow_non_member = FALSE;

    public function __construct() {
        parent::__construct();

        // Sumber waktu tunggal ada di DB (§8.5). READ COMMITTED menyamai
        // sql.LevelReadCommitted yang dipakai seluruh transaksi Go (§9.4).
        $this->db->query("SET SESSION time_zone = '+07:00', SESSION transaction_isolation = 'READ-COMMITTED'");

        Request_guard::api_security_headers();
        $this->_parse_json_body();
    }

    private function _parse_json_body() {
        $method = $this->input->method(TRUE);
        if ( ! in_array($method, array('POST', 'PUT', 'PATCH', 'DELETE'), TRUE)) {
            return;
        }
        // Form-encoded (mis. logout_token back-channel dari IdP) dibaca lewat $this->input->post().
        if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/x-www-form-urlencoded') === 0) {
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
     * (butuh login). Aman dipanggil dua kali; guard-nya idempoten.
     */
    protected function require_member() {
        if ($this->user_id !== NULL) { return; }

        $this->load->model('User_model');
        $this->_require_auth();
        $this->_require_active_user();
    }

    /**
     * Step-up authentication untuk aksi sensitif: user harus memasukkan
     * kata sandi di IdP dalam N menit terakhir. Frontend yang menerima
     * REAUTH_REQUIRED mengarahkan ke /sso/login?prompt=login.
     */
    protected function require_recent_auth() {
        $window = (int) $this->config->item('recent_auth_seconds');
        if ($this->auth_time === NULL || time() - (int) $this->auth_time > $window) {
            $this->fail(Api_exception::reauthRequired());
        }
    }

    /* --- Implementasi guard; dipakai require_member() dan Auth_Controller --- */

    protected function _require_auth() {
        $mode = $this->config->item('auth_mode');

        if ($mode !== 'legacy' && $this->_auth_via_sso_session()) {
            return;
        }
        if ($mode !== 'sso') {
            $this->_auth_via_bearer();
            return;
        }
        $this->fail(Api_exception::unauthorized('silakan masuk terlebih dahulu'));
    }

    /** @return bool TRUE bila request membawa sesi SSO yang valid. */
    private function _auth_via_sso_session() {
        $this->load->library(array('Bff_session', 'Oidc_client'));

        try {
            $s = $this->bff_session->current();
        } catch (Throwable $e) {
            log_message('error', '[auth] penyimpanan sesi tidak terjangkau: ' . $e->getMessage());
            $this->fail(Api_exception::sessionUnavailable());
        }
        if ($s === NULL) {
            return FALSE;
        }

        // Cookie ikut terkirim otomatis oleh browser → method yang mengubah
        // data wajib membuktikan asal request (CSRF, §8).
        if (Request_guard::is_unsafe_method() && ! Request_guard::is_same_origin_xhr()) {
            $this->fail(Api_exception::forbidden('permintaan ditolak (CSRF)'));
        }

        try {
            $fresh = $this->bff_session->ensure_fresh($this->oidc_client);
        } catch (Throwable $e) {
            log_message('error', '[auth] refresh sesi gagal: ' . $e->getMessage());
            $fresh = TRUE;   // gangguan Redis sesaat: sesi tetap sah sampai TTL-nya
        }
        if ( ! $fresh) {
            $this->fail(Api_exception::unauthorized('sesi telah berakhir, silakan masuk kembali'));
        }

        $s = $this->bff_session->current();
        $this->user_id   = (int) $s['user_id'];
        $this->auth_time = (int) $s['auth_time'];
        return TRUE;
    }

    /** Jalur lama (AUTH_MODE legacy/both): langkah 1-4 middleware.RequireAuth. */
    private function _auth_via_bearer() {
        $header = $this->input->get_request_header('Authorization', FALSE);

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
        $this->auth_time  = (int) ($claims['iat'] ?? 0);
    }

    /** Padanan RequireActiveUserDB + syarat keanggotaan: satu query ringan per request. */
    protected function _require_active_user() {
        $state = $this->User_model->get_auth_state($this->user_id);

        if ($state === NULL) {
            $this->fail(Api_exception::unauthorized('akun tidak ditemukan, silakan login kembali'));
        }
        if ($state['status'] !== 'active') {
            $this->fail(Api_exception::accountSuspended());
        }
        if ( ! $this->allow_non_member && $state['member_since'] === NULL) {
            $this->fail(Api_exception::membershipRequired());
        }
    }
}

/**
 * Padanan RequireAuth + RequireActiveUserDB (+ keanggotaan aktif).
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
    // Hak admin ditentukan role, bukan status keanggotaan.
    protected $allow_non_member = TRUE;

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

/**
 * API antar layanan (/koperasi/api/v1/internal/*).
 *
 * Hanya menerima access token client_credentials terbitan JDC Account dengan
 * aud=koperasi. Cookie sesi pengguna TIDAK berlaku di sini (§8 "Integrasi").
 */
class Internal_Controller extends API_Controller {

    /** client_id pemanggil, mis. "market-server" */
    protected $client_id;
    protected $client_scopes = array();

    public function __construct() {
        parent::__construct();

        $header = (string) $this->input->get_request_header('Authorization', FALSE);
        if (stripos($header, 'Bearer ') !== 0) {
            $this->fail(Api_exception::unauthorized('token layanan wajib'));
        }

        $this->load->library('Oidc_client');
        try {
            $claims = $this->oidc_client->verify_service_token(
                trim(substr($header, 7)), $this->config->item('internal_audience'), array());
        } catch (Throwable $e) {
            log_message('error', '[internal] token layanan ditolak: ' . $e->getMessage());
            $this->fail(Api_exception::unauthorized('token layanan tidak valid'));
        }

        $this->client_id     = (string) $claims['client_id'];
        $this->client_scopes = preg_split('/\s+/', trim((string) $claims['scope']));
    }

    protected function require_scope($scope) {
        if ( ! in_array($scope, $this->client_scopes, TRUE)) {
            $this->fail(Api_exception::forbidden('scope ' . $scope . ' diperlukan'));
        }
    }
}
