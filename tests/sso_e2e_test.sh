#!/usr/bin/env bash
# =====================================================================
# Uji end-to-end SSO + Koperasi Pay + Marketplace
# (DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md §10).
#
#   bash tests/sso_e2e_test.sh [ORIGIN]        default http://127.0.0.1:8300
#
# Prasyarat: Apache (mod_rewrite + .htaccess) menyajikan repo ini di ORIGIN,
# RATE_LIMIT_SCALE=50 di ketiga .env (skrip membuat banyak akun dari satu IP),
# MySQL + Redis jalan, ketiga DB sudah dimigrasi, `php account.php cli/keys
# generate` & `cli/clients sync` sudah dijalankan, SMTP_HOST kosong di
# apps/account/.env (OTP & tautan reset dibaca dari log), APP_ENV=development.
# php -S TIDAK cukup: back-channel logout & webhook memanggil server yang sama.
# =====================================================================
set -u

ORIGIN="${1:-${ORIGIN:-http://127.0.0.1:8300}}"
source "$(dirname "$0")/lib/jdc.sh"

PHP_BIN="${PHP_BIN:-php}"
REDIS_CLI="${REDIS_CLI:-redis-cli}"
OPENSSL="${OPENSSL:-openssl}"
ENV_KOP="$ROOT/.env"; ENV_ACC="$ROOT/apps/account/.env"; ENV_MKT="$ROOT/apps/market/.env"
envv() { sed -n "s/^$2=//p" "$1" | tail -1; }
export MYSQL_PWD="${MYSQL_PWD:-$(envv "$ENV_KOP" DB_PASSWORD)}"

K="$ORIGIN/koperasi/api/v1"; M="$ORIGIN/market/api/v1"; A="$ORIGIN/account"
ST=$(date +%s); PW="Kuat#Sandi$ST"; PIN="482913"
KOP_SECRET=$(envv "$ENV_ACC" CLIENT_KOPERASI_SECRET)
SRV_SECRET=$(envv "$ENV_ACC" CLIENT_MARKET_SERVER_SECRET)
HOOK_SECRET=$(envv "$ENV_MKT" KOPERASI_WEBHOOK_SECRET)

b64url() { "$OPENSSL" base64 -A | tr '+/' '-_' | tr -d '='; }
jwt_claim() { # jwt_claim <jwt> <claim>
  "$PHP_BIN" -r '$p=explode(".",$argv[1]); $j=json_decode(base64_decode(strtr($p[1],"-_","+/")),true); $v=$j[$argv[2]]??""; echo is_array($v)?json_encode($v):$v;' "$1" "$2"
}
q() { printf '%s' "$1" | sed -n "s/.*[?&]$2=\([^&]*\).*/\1/p"; }
member_setup() { # member_setup <jar> <nik-suffix>  → KYC + aktivasi (butuh sesi koperasi)
  api "$1" PUT "$K/profile/kyc" "{\"nik\":\"3201$(printf '%012d' "$2")\",\"phone_number\":\"081234567890\",\"address\":\"Jl. Merdeka 1 Bandung\",\"job_title\":\"Wiraswasta\",\"monthly_income\":7500000,\"emergency_contact_name\":\"Siti\",\"emergency_contact_phone\":\"081298765432\"}" >/dev/null
  api "$1" POST "$K/membership/activate" >/dev/null
}

echo "== ORIGIN: $ORIGIN"

# =====================================================================
echo "-- 1. Routing path-based & pengamanan"
c /dev/null "$A/health" >/dev/null;           check "GET /account/health"              "$(jval "$(body)" status)" "ok"
c /dev/null "$K/health" >/dev/null;           check "GET /koperasi/api/v1/health"      "$(jval "$(body)" status)" "ok"
c /dev/null "$M/health" >/dev/null;           check "GET /market/api/v1/health"        "$(jval "$(body)" status)" "ok"
check "compro / → 200"                       "$(c /dev/null "$ORIGIN/")" "200"
check "SPA /koperasi/dashboard → 200 (fallback)" "$(c /dev/null "$ORIGIN/koperasi/dashboard")" "200"
check "SPA /market/orders/1 → 200 (fallback)" "$(c /dev/null "$ORIGIN/market/orders/1")" "200"
check "halaman tak ada → 404"                "$(c /dev/null "$ORIGIN/halaman-tak-ada")" "404"
check "/.env ditolak"                        "$(c /dev/null "$ORIGIN/.env")" "403"
check "/apps/account/keys ditolak"           "$(c /dev/null "$ORIGIN/apps/account/keys/")" "403"
check "/account.php langsung ditolak"        "$(c /dev/null "$ORIGIN/account.php")" "403"
check "URL lama /api/v1/health → 308"        "$(c /dev/null "$ORIGIN/api/v1/health")" "308"
check "URL lama /login → 301"                "$(c /dev/null "$ORIGIN/login")" "301"
check "  … ke /koperasi/dashboard"           "$(location)" "$ORIGIN/koperasi/dashboard"
check "404 API koperasi berformat JSON"      "$(c /dev/null "$K/tidak-ada"; jval "$(body)" code)" "404NOT_FOUND"

# =====================================================================
echo "-- 2. Protokol OIDC di IdP"
c /dev/null "$A/.well-known/openid-configuration" >/dev/null
check "discovery issuer"                     "$(jval "$(body)" issuer)" "$ORIGIN/account"
check "discovery hanya response_type code"   "$(body | grep -o '"response_types_supported":\["code"\]' | wc -l | tr -d ' ')" "1"
c /dev/null "$A/.well-known/jwks.json" >/dev/null
check "JWKS memuat kunci RS256"              "$(body | grep -c '"alg":"RS256"')" "1"

REDIR="$ORIGIN/koperasi/api/v1/sso/callback"
VER="$(head -c 48 /dev/urandom | b64url)"; CHAL="$(printf '%s' "$VER" | "$OPENSSL" dgst -sha256 -binary | b64url)"
authz() { # authz <jar> [extra-query]
  c "$1" "$A/oauth/authorize?response_type=code&client_id=koperasi&redirect_uri=$(printf '%s' "$REDIR" | sed 's/:/%3A/g; s#/#%2F#g')&scope=openid%20profile%20email%20offline_access&state=st$ST&nonce=nn$ST&code_challenge=$CHAL&code_challenge_method=S256${2:-}"
}
check "client tak dikenal → 400 tanpa redirect" "$(c /dev/null "$A/oauth/authorize?client_id=jahat&redirect_uri=https://evil.example/cb&response_type=code")" "400"
check "redirect_uri tak terdaftar → 400"     "$(c /dev/null "$A/oauth/authorize?client_id=koperasi&redirect_uri=https%3A%2F%2Fevil.example%2Fcb&response_type=code")" "400"
c /dev/null "$A/oauth/authorize?response_type=code&client_id=koperasi&redirect_uri=$(printf '%s' "$REDIR" | sed 's/:/%3A/g; s#/#%2F#g')&scope=openid&state=s1&nonce=n1" >/dev/null
check "tanpa PKCE → redirect error invalid_request" "$(q "$(location)" error)" "invalid_request"
JO="$TMPD/oidc"; : > "$JO"
authz "$JO" >/dev/null
check "tanpa sesi → diarahkan ke /account/login" "$(location | cut -d'?' -f1)" "/account/login"
authz "$JO" "&prompt=none" >/dev/null
check "prompt=none tanpa sesi → login_required" "$(q "$(location)" error)" "login_required"

UE="oidc$ST@mail.com"
idp_register "$JO" "Uji Protokol" "$UE" "$PW" >/dev/null
authz "$JO" >/dev/null; CODE1=$(q "$(location)" code)
check "authorize ber-sesi → code + iss (RFC 9207)" "$([ -n "$CODE1" ] && q "$(location)" iss)" "$(printf '%s' "$ORIGIN/account" | sed 's/:/%3A/g; s#/#%2F#g')"
tok() { # tok <form...>  → POST token endpoint dengan client_secret_basic koperasi
  c /dev/null -X POST "$A/oauth/token" -u "koperasi:$KOP_SECRET" "$@"
}
check "verifier PKCE salah → invalid_grant" "$(tok --data-urlencode grant_type=authorization_code --data-urlencode "code=$CODE1" --data-urlencode "redirect_uri=$REDIR" --data-urlencode "code_verifier=$(head -c 48 /dev/urandom | b64url)")$(jval "$(body)" error)" "400invalid_grant"
check "code yang sama dipakai ulang → invalid_grant" "$(tok --data-urlencode grant_type=authorization_code --data-urlencode "code=$CODE1" --data-urlencode "redirect_uri=$REDIR" --data-urlencode "code_verifier=$VER")$(jval "$(body)" error)" "400invalid_grant"
authz "$JO" >/dev/null; CODE2=$(q "$(location)" code)
check "client_secret salah → 401 invalid_client" "$(c /dev/null -X POST "$A/oauth/token" -u "koperasi:salah" --data-urlencode grant_type=authorization_code --data-urlencode "code=$CODE2")$(jval "$(body)" error)" "401invalid_client"
authz "$JO" >/dev/null; CODE3=$(q "$(location)" code)
check "tukar code valid → 200" "$(tok --data-urlencode grant_type=authorization_code --data-urlencode "code=$CODE3" --data-urlencode "redirect_uri=$REDIR" --data-urlencode "code_verifier=$VER")" "200"
IDT=$(jval "$(body)" id_token); AT=$(jval "$(body)" access_token); RT1=$(jval "$(body)" refresh_token)
check "  id_token.nonce cocok"               "$(jwt_claim "$IDT" nonce)" "nn$ST"
check "  id_token.aud = koperasi"            "$(jwt_claim "$IDT" aud)" "koperasi"
check "  refresh_token terbit (offline_access)" "$([ -n "$RT1" ] && echo ya)" "ya"
check "userinfo dengan access token → email" "$(c /dev/null -H "Authorization: Bearer $AT" "$A/oauth/userinfo"; jval "$(body)" email)" "200$UE"

check "refresh RT1 → RT2" "$(tok --data-urlencode grant_type=refresh_token --data-urlencode "refresh_token=$RT1")" "200"
RT2=$(jval "$(body)" refresh_token)
check "RT1 dipakai lagi dalam jendela balapan → ditolak" "$(tok --data-urlencode grant_type=refresh_token --data-urlencode "refresh_token=$RT1")" "400"
check "RT2 tetap berlaku → RT3" "$(tok --data-urlencode grant_type=refresh_token --data-urlencode "refresh_token=$RT2")" "200"
RT3=$(jval "$(body)" refresh_token)
FAM=$(sql jdc_account "SELECT family_id FROM oauth_refresh_tokens WHERE token_hash=SHA2('$RT2',256)")
sql jdc_account "UPDATE oauth_refresh_tokens SET used_at = used_at - INTERVAL 120 SECOND WHERE family_id='$FAM' AND used_at IS NOT NULL"
check "RT2 dipakai ulang di luar jendela → reuse terdeteksi" "$(tok --data-urlencode grant_type=refresh_token --data-urlencode "refresh_token=$RT2")" "400"
check "  seluruh keluarga dicabut: RT3 ikut mati" "$(tok --data-urlencode grant_type=refresh_token --data-urlencode "refresh_token=$RT3")" "400"
check "  audit refresh_token_reuse tercatat" "$(sql jdc_account "SELECT COUNT(*)>0 FROM audit_logs WHERE event='refresh_token_reuse'")" "1"

c /dev/null -X POST "$A/oauth/token" -u "market-server:$SRV_SECRET" --data-urlencode grant_type=client_credentials >/dev/null
SVC=$(jval "$(body)" access_token)
check "client_credentials market-server → token layanan" "$(jwt_claim "$SVC" token_use)" "service"
check "API internal dengan token layanan → 200" "$(c /dev/null -H "Authorization: Bearer $SVC" "$K/internal/members/999999")" "200"
check "API internal dengan token PENGGUNA → 401" "$(c /dev/null -H "Authorization: Bearer $AT" "$K/internal/members/1")" "401"
check "API internal tanpa token → 401"       "$(c /dev/null "$K/internal/members/1")" "401"
check "client koperasi tak boleh client_credentials" "$(tok --data-urlencode grant_type=client_credentials)$(jval "$(body)" error)" "400unauthorized_client"

# =====================================================================
echo "-- 3. JDC Account: registrasi, login, keamanan form"
JR="$TMPD/reg"; : > "$JR"
c "$JR" "$A/register" >/dev/null
check "POST form tanpa CSRF → 403" "$(c "$JR" -X POST "$A/register" --data-urlencode "name=Tanpa Token" --data-urlencode "email=x$ST@mail.com")" "403"
T=$(csrf_of "$TMPD/body"); c "$JR" "$A/register" >/dev/null; T=$(csrf_of "$TMPD/body")
c "$JR" -X POST "$A/register" --data-urlencode "_csrf=$T" --data-urlencode "name=Sandi Lemah" --data-urlencode "email=weak$ST@mail.com" --data-urlencode "password=password123" --data-urlencode "password_confirm=password123" --data-urlencode "agree=1" >/dev/null
check "kata sandi umum ditolak" "$(body | grep -c 'terlalu umum')" "1"
c "$JR" -X POST "$A/register" --data-urlencode "_csrf=$T" --data-urlencode "name=Duplikat" --data-urlencode "email=$UE" --data-urlencode "password=$PW" --data-urlencode "password_confirm=$PW" --data-urlencode "agree=1" >/dev/null
check "email terverifikasi terdaftar ulang → ditolak" "$(body | grep -c 'Email sudah terdaftar')" "1"

LE="lock$ST@mail.com"; JL="$TMPD/lock"; : > "$JL"
idp_register "$JL" "Uji Kunci" "$LE" "$PW" >/dev/null; : > "$JL"
for i in 1 2 3 4 5; do idp_login "$JL" "$LE" "salah-$i" >/dev/null; done
idp_login "$JL" "$LE" "$PW" >/dev/null
check "5x salah → login benar pun terkunci sementara" "$(body | grep -c 'Terlalu banyak percobaan')" "1"

OE="otp$ST@mail.com"; JV="$TMPD/otp"; : > "$JV"
c "$JV" "$A/register" >/dev/null; T=$(csrf_of "$TMPD/body")
c "$JV" -X POST "$A/register" --data-urlencode "_csrf=$T" --data-urlencode "name=Uji OTP" --data-urlencode "email=$OE" --data-urlencode "password=$PW" --data-urlencode "password_confirm=$PW" --data-urlencode "agree=1" >/dev/null
GOOD=$(otp_for "$OE")
for i in 1 2 3 4 5; do c "$JV" -X POST "$A/verify-email" --data-urlencode "_csrf=$T" --data-urlencode "email=$OE" --data-urlencode "otp=00000$i" >/dev/null; done
c "$JV" -X POST "$A/verify-email" --data-urlencode "_csrf=$T" --data-urlencode "email=$OE" --data-urlencode "otp=$GOOD" >/dev/null
check "5x OTP salah → OTP benar pun hangus" "$(body | grep -c 'Kode verifikasi salah')" "1"

# =====================================================================
echo "-- 4. SSO lintas layanan (BFF)"
UA="alice$ST@mail.com"; JA="$TMPD/alice"; : > "$JA"
idp_register "$JA" "Alice Anggota" "$UA" "$PW" >/dev/null
check "login SSO ke koperasi → mendarat di dashboard" "$(app_login "$JA" koperasi)" "$ORIGIN/koperasi/dashboard"
check "  GET /profile dengan cookie sesi → 200" "$(api "$JA" GET "$K/profile")" "200"
check "  cookie sesi HttpOnly & Path=/koperasi" "$(grep -c "^#HttpOnly_127.0.0.1.*/koperasi.*kop_sid" "$JA")" "1"
check "  token TIDAK ada di body /profile" "$(body | grep -c 'eyJ')" "0"
check "  PUT tanpa header XHR (CSRF) → 403" "$(c "$JA" -X PUT "$K/profile/kyc" -H 'Content-Type: application/json' -d '{}')" "403"
check "belum anggota: /savings → 403 MEMBERSHIP_REQUIRED" "$(api "$JA" GET "$K/savings/accounts"; jval "$(body)" code)" "403MEMBERSHIP_REQUIRED"
check "endpoint login lama → 410" "$(c "$JA" -X POST "$K/login" -H 'Content-Type: application/json' -d '{}')" "410"
check "SSO senyap ke marketplace (tanpa form login)" "$(app_login "$JA" market)" "$ORIGIN/market/"
check "  /market/api/v1/me → 200" "$(api "$JA" GET "$M/me"; jval "$(body)" email)" "200$UA"

JG="$TMPD/guest"; : > "$JG"
check "tamu prompt=none → kembali sebagai tamu" "$(app_login "$JG" market none)" "$ORIGIN/market/"
check "  tamu /me → 401" "$(api "$JG" GET "$M/me")" "401"
check "return_to ke domain lain diabaikan" "$(curl -s -L -b "$JA" -c "$JA" -o /dev/null -w '%{url_effective}' "$K/sso/login?return_to=https://evil.example/")" "$ORIGIN/koperasi/"
check "callback dengan state palsu → halaman error" "$(c "$JA" "$K/sso/callback?code=x&state=palsu"; location)" "302/koperasi/auth/error?code=invalid_state"

# refresh sesi BFF saat access token lewat masa berlaku
SID=$(awk '$6 ~ /kop_sid$/ {print $7}' "$JA" | tail -1); SH=$(printf '%s' "$SID" | "$OPENSSL" dgst -sha256 | sed 's/^.* //')
SESS=$("$REDIS_CLI" GET "bff:sess:$SH")
"$REDIS_CLI" SET "bff:sess:$SH" "$(printf '%s' "$SESS" | sed 's/"access_expires_at":[0-9]*/"access_expires_at":1/')" EX 600 >/dev/null
USED_BEFORE=$(sql jdc_account "SELECT COUNT(*) FROM oauth_refresh_tokens t JOIN idp_sessions s ON s.id=t.session_id JOIN users u ON u.id=s.user_id WHERE u.email='$UA' AND t.used_at IS NOT NULL")
check "access token kedaluwarsa → BFF refresh otomatis, request tetap 200" "$(api "$JA" GET "$K/profile")" "200"
check "  refresh token dirotasi di IdP" "$(sql jdc_account "SELECT COUNT(*) FROM oauth_refresh_tokens t JOIN idp_sessions s ON s.id=t.session_id JOIN users u ON u.id=s.user_id WHERE u.email='$UA' AND t.used_at IS NOT NULL")" "$((USED_BEFORE + 1))"

# back-channel logout: keluar di IdP → semua layanan
c "$JA" "$A" >/dev/null; T=$(csrf_of "$TMPD/body")
c "$JA" -X POST "$A/logout" --data-urlencode "_csrf=$T" >/dev/null
check "logout di Akun JDC → sesi koperasi mati" "$(api "$JA" GET "$K/profile")" "401"
check "  → sesi marketplace mati" "$(api "$JA" GET "$M/me")" "401"
check "  back-channel terkirim ke 2 client" "$(sql jdc_account "SELECT COUNT(*) FROM backchannel_jobs b JOIN idp_sessions s ON s.sid=b.sid JOIN users u ON u.id=s.user_id WHERE u.email='$UA' AND b.delivered_at IS NOT NULL")" "2"

# logout dari layanan (RP-initiated)
: > "$JA"; idp_login "$JA" "$UA" "$PW" >/dev/null; app_login "$JA" koperasi >/dev/null; app_login "$JA" market >/dev/null
check "logout marketplace tanpa header XHR → 403" "$(c "$JA" -X POST "$M/sso/logout")" "403"
api "$JA" POST "$M/sso/logout" >/dev/null; RU=$(jval "$(body)" redirect_url)
check "logout marketplace → URL end_session IdP" "$(printf '%s' "$RU" | cut -d'?' -f1)" "$A/oauth/logout"
c "$JA" "$RU" >/dev/null
check "  end_session → kembali ke compro" "$(location)" "$ORIGIN/"
check "  koperasi ikut keluar" "$(api "$JA" GET "$K/profile")" "401"

# reset kata sandi mencabut semua sesi
: > "$JA"; idp_login "$JA" "$UA" "$PW" >/dev/null; app_login "$JA" koperasi >/dev/null
JF="$TMPD/forgot"; : > "$JF"; c "$JF" "$A/forgot-password" >/dev/null; T=$(csrf_of "$TMPD/body")
c "$JF" -X POST "$A/forgot-password" --data-urlencode "_csrf=$T" --data-urlencode "email=$UA" >/dev/null
check "lupa sandi email tak terdaftar → balasan generik sama" "$(c "$JF" -X POST "$A/forgot-password" --data-urlencode "_csrf=$T" --data-urlencode "email=tidakada$ST@mail.com"; location)" "302/account/forgot-password?msg=reset_sent"
LINK=$(reset_link_for "$UA"); TOKEN=$(q "$LINK" token)
NPW="Baru#Sandi$ST"
c "$JF" "$LINK" >/dev/null; T=$(csrf_of "$TMPD/body")
check "reset kata sandi → 302 ke login" "$(c "$JF" -X POST "$A/reset-password" --data-urlencode "_csrf=$T" --data-urlencode "token=$TOKEN" --data-urlencode "password=$NPW" --data-urlencode "password_confirm=$NPW")" "302"
check "  sesi lama di koperasi dicabut" "$(api "$JA" GET "$K/profile")" "401"
check "  tautan reset sekali pakai" "$(c "$JF" "$LINK")" "400"
check "  login dengan sandi baru" "$(: > "$JA"; idp_login "$JA" "$UA" "$NPW"; location)" "302/account"
PW_A="$NPW"

# blokir global oleh admin platform
UB="bad$ST@mail.com"; JB="$TMPD/bad"; : > "$JB"
idp_register "$JB" "Akun Bermasalah" "$UB" "$PW" >/dev/null; app_login "$JB" koperasi >/dev/null; app_login "$JB" market >/dev/null
"$PHP_BIN" "$ROOT/account.php" cli/users promote "$UA" >/dev/null
c "$JA" "$A/admin/users?q=$UB" >/dev/null; T=$(csrf_of "$TMPD/body"); BID=$(sql jdc_account "SELECT id FROM users WHERE email='$UB'")
check "admin platform memblokir akun" "$(c "$JA" -X POST "$A/admin/users/$BID/status" --data-urlencode "_csrf=$T" --data-urlencode "status=banned")" "302"
check "  sesi koperasi akun terblokir mati" "$(api "$JB" GET "$K/profile")" "401"
check "  sesi marketplace akun terblokir mati" "$(api "$JB" GET "$M/me")" "401"
idp_login "$JB" "$UB" "$PW" >/dev/null
check "  login ulang ditolak" "$(body | grep -c 'dinonaktifkan')" "1"
JN="$TMPD/nonadmin"; : > "$JN"; idp_register "$JN" "Bukan Admin" "na$ST@mail.com" "$PW" >/dev/null
check "non-admin membuka /account/admin/users → 403" "$(c "$JN" "$A/admin/users")" "403"

# =====================================================================
echo "-- 5. Koperasi Pay & Marketplace"
SE="seller$ST@mail.com"; BE="buyer$ST@mail.com"; XE="nonmember$ST@mail.com"
JS="$TMPD/s"; JBY="$TMPD/b"; JX="$TMPD/x"; : > "$JS"; : > "$JBY"; : > "$JX"
idp_register "$JS" "Penjual Uji" "$SE" "$PW" >/dev/null;  app_login "$JS" koperasi >/dev/null;  member_setup "$JS" "${ST}1"; app_login "$JS" market >/dev/null
idp_register "$JBY" "Pembeli Uji" "$BE" "$PW" >/dev/null; app_login "$JBY" koperasi >/dev/null; member_setup "$JBY" "${ST}2"; app_login "$JBY" market >/dev/null
idp_register "$JX" "Belum Anggota" "$XE" "$PW" >/dev/null; app_login "$JX" market >/dev/null

check "non-anggota tidak bisa buka toko → 422" "$(api "$JX" POST "$M/seller/store" '{"name":"Toko X","city":"Bogor"}'; jval "$(body)" code)" "422SELLER_NOT_ELIGIBLE"
check "anggota ber-KYC buka toko → 201" "$(api "$JS" POST "$M/seller/store" "{\"name\":\"Toko Uji $ST\",\"description\":\"uji\",\"city\":\"Bandung\"}")" "201"
STORE=$(jval "$(body)" id)
check "harga anggota > harga normal ditolak" "$(api "$JS" POST "$M/seller/products" '{"name":"Produk Salah","price":"100","member_price":"200","stock":1,"status":"active"}'; jval "$(body)" code)" "400INVALID_MEMBER_PRICE"
check "image_url non-https ditolak" "$(api "$JS" POST "$M/seller/products" '{"name":"Produk XSS","price":"100","stock":1,"image_url":"javascript:alert(1)","status":"active"}')" "400"
api "$JS" POST "$M/seller/products" '{"name":"Madu Hutan 500ml","description":"uji","price":"150000","member_price":"125000","stock":5,"weight_gram":600,"status":"active"}' >/dev/null
PROD=$(jval "$(body)" id)
check "katalog publik tanpa login memuat produk" "$(c /dev/null "$M/products/$PROD"; jval "$(body)" member_price)" "200125000"
check "penjual memasukkan produk sendiri ke keranjang → 422" "$(api "$JS" PUT "$M/cart/items" "{\"product_id\":$PROD,\"qty\":1}"; jval "$(body)" code)" "422OWN_PRODUCT"
api "$JX" PUT "$M/cart/items" "{\"product_id\":$PROD,\"qty\":1}" >/dev/null
check "checkout non-anggota → 422" "$(api "$JX" POST "$M/checkout" "{\"store_id\":$STORE,\"recipient_name\":\"Belum Anggota\",\"recipient_phone\":\"081234567890\",\"shipping_address\":\"Jl. Uji No. 1, Bandung\"}"; jval "$(body)" code)" "422MEMBERSHIP_REQUIRED_FOR_PAYMENT"

# saldo pembeli: buka Simpanan Sukarela + setoran disetujui admin koperasi
api "$JBY" POST "$K/savings/accounts" '{"savings_product_id":3}' >/dev/null; ACC=$(jval "$(body)" id)
api "$JBY" POST "$K/savings/deposit" "{\"account_id\":$ACC,\"amount\":500000,\"payment_method\":\"manual_transfer\",\"reference_id\":\"TRF-$ST\"}" >/dev/null; DREQ=$(jval "$(body)" id)
sql koperasi_digital "UPDATE users SET role='super_admin' WHERE email='$SE'"
api "$JS" PUT "$K/admin/savings/deposit-requests/$DREQ/review" '{"action":"approve"}' >/dev/null

api "$JBY" PUT "$M/cart/items" "{\"product_id\":$PROD,\"qty\":2}" >/dev/null
check "checkout anggota → 201 + payment_url" "$(api "$JBY" POST "$M/checkout" "{\"store_id\":$STORE,\"recipient_name\":\"Pembeli Uji\",\"recipient_phone\":\"081234567890\",\"shipping_address\":\"Jl. Uji No. 2, Bandung\"}")" "201"
O1=$(body | sed -n 's/^{"order":{"id":\([0-9]*\).*/\1/p'); PI1=$(body | sed -n 's/.*"payment_url":"[^"]*\/pay\/\(pi_[a-f0-9]*\)".*/\1/p')
check "  total memakai harga anggota (2 × 125.000)" "$(sql jdc_market "SELECT CAST(total AS UNSIGNED) FROM orders WHERE id=$O1")" "250000"
check "  stok dikurangi saat checkout" "$(sql jdc_market "SELECT stock FROM products WHERE id=$PROD")" "3"

check "penjual membuka tagihan milik pembeli → 404" "$(api "$JS" GET "$K/payments/$PI1")" "404"
check "bayar sebelum punya PIN → 422 PIN_NOT_SET" "$(api "$JBY" POST "$K/payments/$PI1/confirm" "{\"account_id\":$ACC,\"pin\":\"$PIN\"}"; jval "$(body)" code)" "422PIN_NOT_SET"
api "$JBY" PUT "$K/security/pin" "{\"pin\":\"$PIN\"}" >/dev/null
check "rekening simpanan wajib tidak bisa dipakai bayar" "$(api "$JBY" POST "$K/payments/$PI1/confirm" "{\"account_id\":$(sql koperasi_digital "SELECT a.id FROM savings_accounts a JOIN users u ON u.id=a.user_id WHERE u.email='$BE' AND a.savings_product_id=1"),\"pin\":\"$PIN\"}"; jval "$(body)" code)" "422PAYMENT_ACCOUNT_INELIGIBLE"

# idempotensi API internal
IDEM="uji-idem-$ST"
idem_call() { c /dev/null -X POST "$K/internal/payment-intents" -H "Authorization: Bearer $SVC" -H "Idempotency-Key: $IDEM" -H 'Content-Type: application/json' -d "$1"; }
PAYLOAD="{\"merchant_ref\":\"IDEM-$ST\",\"payer_sub\":\"$(sql koperasi_digital "SELECT sso_sub FROM users WHERE email='$BE'")\",\"payee_sub\":\"$(sql koperasi_digital "SELECT sso_sub FROM users WHERE email='$SE'")\",\"amount\":\"1000.00\",\"description\":\"uji idempotensi\",\"return_url\":\"$ORIGIN/market/orders/0\"}"
check "buat tagihan internal → 201" "$(idem_call "$PAYLOAD")" "201"; IDP1=$(jval "$(body)" id)
check "  kunci sama, isi sama → 200 tagihan sama" "$(idem_call "$PAYLOAD"; jval "$(body)" id)" "200$IDP1"
check "  kunci sama, isi beda → 409" "$(idem_call "${PAYLOAD/1000.00/2000.00}"; jval "$(body)" code)" "409IDEMPOTENCY_CONFLICT"
check "  amount number (bukan string) ditolak" "$(c /dev/null -X POST "$K/internal/payment-intents" -H "Authorization: Bearer $SVC" -H "Idempotency-Key: x-$IDEM" -H 'Content-Type: application/json' -d "${PAYLOAD/\"1000.00\"/1000}")" "400"
check "  return_url di luar /market/ ditolak" "$(c /dev/null -X POST "$K/internal/payment-intents" -H "Authorization: Bearer $SVC" -H "Idempotency-Key: y-$IDEM" -H 'Content-Type: application/json' -d "${PAYLOAD/\/market\/orders\/0/\/evil}")" "400"

# konfirmasi bersamaan: tepat satu debet
BAL0=$(sql koperasi_digital "SELECT CAST(balance AS CHAR) FROM savings_accounts WHERE id=$ACC")
for n in a b; do
  ( curl -s -b "$JBY" -o /dev/null -w '%{http_code}' -X POST "$K/payments/$PI1/confirm" -H 'X-Requested-With: XMLHttpRequest' -H 'Content-Type: application/json' -d "{\"account_id\":$ACC,\"pin\":\"$PIN\"}" > "$TMPD/conf_$n" ) &
done
wait
CODES="$(cat "$TMPD/conf_a") $(cat "$TMPD/conf_b")"
check "2 konfirmasi bersamaan → tepat satu 200 [$CODES]" "$(printf '%s\n' $CODES | grep -c '^200$')" "1"
check "  saldo didebet TEPAT sekali" "$(sql koperasi_digital "SELECT CAST($BAL0 - balance AS UNSIGNED) FROM savings_accounts WHERE id=$ACC")" "250000"
check "  webhook → pesanan paid" "$(sql jdc_market "SELECT status FROM orders WHERE id=$O1")" "paid"

check "penjual kirim (resi)" "$(api "$JS" POST "$M/seller/orders/$O1/ship" '{"tracking_number":"JNE00112233"}'; jval "$(body)" status)" "200shipped"
SELLER_BAL0=$(sql koperasi_digital "SELECT COALESCE(CAST(SUM(a.balance) AS UNSIGNED),0) FROM savings_accounts a JOIN users u ON u.id=a.user_id WHERE u.email='$SE' AND a.savings_product_id=3")
check "pembeli terima pesanan → completed" "$(api "$JBY" POST "$M/orders/$O1/complete"; jval "$(body)" status)" "200completed"
check "  dana diteruskan ke Simpanan Sukarela penjual" "$(sql koperasi_digital "SELECT CAST(SUM(a.balance) AS UNSIGNED) - $SELLER_BAL0 FROM savings_accounts a JOIN users u ON u.id=a.user_id WHERE u.email='$SE' AND a.savings_product_id=3")" "250000"
check "  payout tercatat done" "$(sql jdc_market "SELECT payout_status FROM orders WHERE id=$O1")" "done"

# refund: dibayar lalu dibatalkan penjual
api "$JBY" PUT "$M/cart/items" "{\"product_id\":$PROD,\"qty\":1}" >/dev/null
api "$JBY" POST "$M/checkout" "{\"store_id\":$STORE,\"recipient_name\":\"Pembeli Uji\",\"recipient_phone\":\"081234567890\",\"shipping_address\":\"Jl. Uji No. 2, Bandung\"}" >/dev/null
O2=$(body | sed -n 's/^{"order":{"id":\([0-9]*\).*/\1/p'); PI2=$(body | sed -n 's/.*\/pay\/\(pi_[a-f0-9]*\)".*/\1/p')
api "$JBY" POST "$K/payments/$PI2/confirm" "{\"account_id\":$ACC,\"pin\":\"$PIN\"}" >/dev/null
BAL1=$(sql koperasi_digital "SELECT CAST(balance AS CHAR) FROM savings_accounts WHERE id=$ACC")
STOCK1=$(sql jdc_market "SELECT stock FROM products WHERE id=$PROD")
check "penjual batalkan pesanan dibayar → cancelled" "$(api "$JS" POST "$M/seller/orders/$O2/cancel" '{"reason":"stok rusak saat pengemasan"}'; jval "$(body)" status)" "200cancelled"
check "  dana dikembalikan ke rekening pembeli" "$(sql koperasi_digital "SELECT CAST(balance - $BAL1 AS UNSIGNED) FROM savings_accounts WHERE id=$ACC")" "125000"
check "  stok dikembalikan" "$(sql jdc_market "SELECT stock - $STOCK1 FROM products WHERE id=$PROD")" "1"
check "  status tagihan refunded" "$(c /dev/null -H "Authorization: Bearer $SVC" "$K/internal/payment-intents/$PI2"; jval "$(body)" status)" "200refunded"

# batal sebelum bayar
api "$JBY" PUT "$M/cart/items" "{\"product_id\":$PROD,\"qty\":1}" >/dev/null
api "$JBY" POST "$M/checkout" "{\"store_id\":$STORE,\"recipient_name\":\"Pembeli Uji\",\"recipient_phone\":\"081234567890\",\"shipping_address\":\"Jl. Uji No. 2, Bandung\"}" >/dev/null
O3=$(body | sed -n 's/^{"order":{"id":\([0-9]*\).*/\1/p'); PI3=$(body | sed -n 's/.*\/pay\/\(pi_[a-f0-9]*\)".*/\1/p')
check "pembeli batalkan pesanan belum dibayar" "$(api "$JBY" POST "$M/orders/$O3/cancel" '{}'; jval "$(body)" status)" "200cancelled"
check "  tagihan koperasi ikut dibatalkan" "$(c /dev/null -H "Authorization: Bearer $SVC" "$K/internal/payment-intents/$PI3"; jval "$(body)" status)" "200cancelled"
check "  tagihan batal tidak bisa dibayar" "$(api "$JBY" POST "$K/payments/$PI3/confirm" "{\"account_id\":$ACC,\"pin\":\"$PIN\"}"; jval "$(body)" code)" "409PAYMENT_NOT_CONFIRMABLE"

# saldo kurang & PIN terkunci
sql jdc_market "UPDATE products SET stock=100 WHERE id=$PROD"
api "$JBY" PUT "$M/cart/items" "{\"product_id\":$PROD,\"qty\":20}" >/dev/null
api "$JBY" POST "$M/checkout" "{\"store_id\":$STORE,\"recipient_name\":\"Pembeli Uji\",\"recipient_phone\":\"081234567890\",\"shipping_address\":\"Jl. Uji No. 2, Bandung\"}" >/dev/null
PI4=$(body | sed -n 's/.*\/pay\/\(pi_[a-f0-9]*\)".*/\1/p')
check "saldo tidak cukup → 422" "$(api "$JBY" POST "$K/payments/$PI4/confirm" "{\"account_id\":$ACC,\"pin\":\"$PIN\"}"; jval "$(body)" code)" "422INSUFFICIENT_BALANCE"
for i in 1 2 3 4 5; do api "$JBY" POST "$K/payments/$PI4/confirm" "{\"account_id\":$ACC,\"pin\":\"11122$i\"}" >/dev/null; done
check "5x PIN salah → terkunci (423)" "$(api "$JBY" POST "$K/payments/$PI4/confirm" "{\"account_id\":$ACC,\"pin\":\"$PIN\"}"; jval "$(body)" code)" "423PIN_LOCKED"

# webhook
EVT="{\"id\":\"evt_uji$ST\",\"type\":\"payment.held\",\"created\":$ST,\"data\":{\"payment_intent\":{\"id\":\"pi_000000000000000000000000\",\"status\":\"held\"}}}"
TS=$(date +%s)
SIGHEX=$(printf '%s' "$TS.$EVT" | "$OPENSSL" dgst -sha256 -hmac "$HOOK_SECRET" | sed 's/^.* //')
SIG="v1=$SIGHEX"
check "webhook tanda tangan salah → 401" "$(c /dev/null -X POST "$M/webhooks/koperasi" -H "X-JDC-Timestamp: $TS" -H 'X-JDC-Signature: v1=deadbeef' -H 'Content-Type: application/json' -d "$EVT")" "401"
check "webhook timestamp basi → 401" "$(c /dev/null -X POST "$M/webhooks/koperasi" -H "X-JDC-Timestamp: $((TS-3600))" -H "X-JDC-Signature: $SIG" -H 'Content-Type: application/json' -d "$EVT")" "401"
check "webhook sah → 200" "$(c /dev/null -X POST "$M/webhooks/koperasi" -H "X-JDC-Timestamp: $TS" -H "X-JDC-Signature: $SIG" -H 'Content-Type: application/json' -d "$EVT"; jval "$(body)" status)" "200ok"
check "  event sama dikirim ulang → duplicate" "$(c /dev/null -X POST "$M/webhooks/koperasi" -H "X-JDC-Timestamp: $TS" -H "X-JDC-Signature: $SIG" -H 'Content-Type: application/json' -d "$EVT"; jval "$(body)" status)" "200duplicate"

"$PHP_BIN" "$ROOT/index.php" cli/ledger_audit run >/dev/null 2>&1
AUDIT_EXIT=$?
check "audit buku besar bersih, termasuk rekening penampung" "$AUDIT_EXIT" "0"

rm -rf "$TMPD"
echo
printf 'HASIL SSO/E2E: \033[32m%d lulus\033[0m, \033[31m%d gagal\033[0m\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
