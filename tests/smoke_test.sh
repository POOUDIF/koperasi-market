#!/usr/bin/env bash
# =====================================================================
# Verifikasi manual §22 — dijalankan berurutan, tiap langkah bergantung
# pada hasil sebelumnya.
#
#   bash tests/smoke_test.sh [ORIGIN]        default http://127.0.0.1:8300
#
# Sejak SSO (DOCS/ARSITEKTUR_SSO_COMPRO_MARKETPLACE.md) login lewat JDC Account
# dan API koperasi memakai cookie sesi — skrip ini melewati alur login yang
# sama persis dengan browser (tests/lib/jdc.sh), tanpa backdoor.
#
# Prasyarat: Apache menyajikan repo ini di ORIGIN, MySQL + Redis jalan, skema
# & seed sudah diterapkan, SMTP_HOST kosong di apps/account/.env.
# =====================================================================
set -u

ORIGIN="${1:-${ORIGIN:-http://127.0.0.1:8300}}"
source "$(dirname "$0")/lib/jdc.sh"
export MYSQL_PWD="${MYSQL_PWD:-$(sed -n 's/^DB_PASSWORD=//p' "$ROOT/.env" | tail -1)}"

BASE="$ORIGIN/koperasi/api/v1"
MYSQL="$MYSQL_BIN"

PASS=0; FAIL=0
STAMP=$(date +%s)
MEMBER="budi${STAMP}@mail.com"
ADMIN="admin${STAMP}@mail.com"
PW="Rahasia#${STAMP}"
TOKEN="$TMPD/member.jar"; ADMIN_TOKEN="$TMPD/admin.jar"; : > "$TOKEN"; : > "$ADMIN_TOKEN"

# --- util -------------------------------------------------------------
check() { # check <label> <actual> <expected>
  if [ "$2" = "$3" ]; then printf '  [32mOK[0m   %-58s %s
' "$1" "$2"; PASS=$((PASS+1))
  else printf '  [31mFAIL[0m %-58s got=%s want=%s
' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}

code() { # code <method> <path> [json] [cookie-jar]
  local m="$1" p="$2" d="${3:-}" t="${4:-}"
  local args=(-s -o /tmp/body.$$ -w '%{http_code}' -X "$m" "$BASE$p" -H 'Content-Type: application/json' -H 'X-Requested-With: XMLHttpRequest')
  [ -n "$t" ] && args+=(-b "$t" -c "$t")
  [ -n "$d" ] && args+=(-d "$d")
  curl "${args[@]}"
}
body() { cat /tmp/body.$$; }

echo "== BASE: $BASE"

# --- 1. Health --------------------------------------------------------
echo "-- Fase 0: fondasi"
c=$(code GET /health); check "GET /health" "$c" "200"
check "  services.database" "$(jval "$(body)" database)" "ok"
check "  services.redis"    "$(jval "$(body)" redis)"    "ok"
c=$(code GET /tidak-ada); check "route tak dikenal -> 404 JSON" "$c" "404"

# --- 2..7 Auth (SSO) ---------------------------------------------------
echo "-- Fase 1: autentikasi lewat JDC Account"
c=$(code POST /login "{\"email\":\"$MEMBER\",\"password\":\"$PW\"}")
check "endpoint login lama -> 410 Gone" "$c" "410"

idp_register "$TOKEN" "Budi Santoso" "$MEMBER" "$PW" >/dev/null
check "registrasi + OTP di JDC Account" "$(sql jdc_account "SELECT email_verified_at IS NOT NULL FROM users WHERE email='$MEMBER'")" "1"
check "login SSO ke koperasi" "$(app_login "$TOKEN" koperasi)" "$ORIGIN/koperasi/dashboard"

c=$(code GET /profile "" "$TOKEN"); check "GET /profile (cookie sesi)" "$c" "200"
check "  password_hash TIDAK bocor" "$(printf '%s' "$(body)" | grep -c password_hash)" "0"
check "  akun baru belum anggota" "$(jval "$(body)" is_member)" "false"

printf '127.0.0.1	FALSE	/koperasi	FALSE	0	kop_sid	cookie-palsu-%064d
' 0 > "$TMPD/fake.jar"
c=$(code GET /profile "" "$TMPD/fake.jar"); check "cookie sesi palsu -> 401" "$c" "401"
c=$(code GET /profile); check "tanpa cookie sesi -> 401" "$c" "401"

c=$(code GET /savings/accounts "" "$TOKEN"); check "belum anggota -> 403 MEMBERSHIP_REQUIRED" "$c" "403"
c=$(code POST /membership/activate "" "$TOKEN"); check "aktivasi tanpa KYC -> 422" "$c" "422"
c=$(code PUT /profile/kyc "{\"nik\":\"320199$(printf '%010d' "$STAMP")\",\"phone_number\":\"081234567890\",\"address\":\"Jl. Merdeka 1\",\"job_title\":\"Wiraswasta\",\"monthly_income\":7500000,\"emergency_contact_name\":\"Siti\",\"emergency_contact_phone\":\"081298765432\"}" "$TOKEN")
check "isi KYC -> 200" "$c" "200"
c=$(code POST /membership/activate "" "$TOKEN"); check "aktivasi keanggotaan -> 201" "$c" "201"
c=$(code POST /membership/activate "" "$TOKEN"); check "aktivasi kedua -> 409" "$c" "409"

# --- Fase 2: simpanan -------------------------------------------------
echo "-- Fase 2: simpanan"
c=$(code GET /savings/accounts "" "$TOKEN"); check "GET /savings/accounts" "$c" "200"
NACC=$(printf '%s' "$(body)" | grep -o '"savings_product_id"' | wc -l | tr -d ' ')
check "  2 rekening wajib otomatis" "$NACC" "2"
ACC=$(jval "$(body)" id)

c=$(code POST /savings/deposit "{\"account_id\":$ACC,\"amount\":1000,\"payment_method\":\"manual_transfer\"}" "$TOKEN")
check "setoran di bawah minimum -> 422" "$c" "422"

c=$(code POST /savings/deposit "{\"account_id\":$ACC,\"amount\":5000000,\"payment_method\":\"manual_transfer\",\"reference_id\":\"TRF-001\"}" "$TOKEN")
check "ajukan setoran 5.000.000 -> 201" "$c" "201"
REQ=$(jval "$(body)" id)
check "  status awal pending" "$(jval "$(body)" status)" "pending"

c=$(code POST /savings/deposit "{\"account_id\":999999,\"amount\":5000000,\"payment_method\":\"x\"}" "$TOKEN")
check "setor ke rekening asing -> 404" "$c" "404"

# admin: daftar di JDC Account, login SSO, lalu naikkan role koperasi lewat SQL
idp_register "$ADMIN_TOKEN" "Admin Koperasi" "$ADMIN" "$PW" >/dev/null
app_login "$ADMIN_TOKEN" koperasi >/dev/null

c=$(code GET /admin/users "" "$TOKEN"); check "anggota akses /admin/users -> 403" "$c" "403"

"$MYSQL" -u root -h 127.0.0.1 koperasi_digital   -e "UPDATE users SET role='super_admin' WHERE email='$ADMIN';" 2>/dev/null

c=$(code GET /admin/users "" "$ADMIN_TOKEN"); check "admin akses /admin/users -> 200" "$c" "200"
check "  password_hash TIDAK bocor" "$(printf '%s' "$(body)" | grep -c password_hash)" "0"
check "  berpaginasi (ada per_page)" "$(printf '%s' "$(body)" | grep -c per_page)" "1"

# CACAT-03: di versi Go, grup /admin melewatkan RequireActiveUserDB sehingga
# admin yang di-banned masih bisa meng-approve selama tokennya belum kedaluwarsa.
"$MYSQL" -u root -h 127.0.0.1 koperasi_digital \
  -e "UPDATE users SET status='banned' WHERE email='$ADMIN';" 2>/dev/null
c=$(code GET /admin/users "" "$ADMIN_TOKEN"); check "admin di-banned ditolak -> 403 (CACAT-03)" "$c" "403"
c=$(code PUT "/admin/savings/deposit-requests/$REQ/review" '{"action":"approve"}' "$ADMIN_TOKEN")
check "  approve oleh admin banned -> 403" "$c" "403"
"$MYSQL" -u root -h 127.0.0.1 koperasi_digital \
  -e "UPDATE users SET status='active' WHERE email='$ADMIN';" 2>/dev/null

c=$(code PUT "/admin/savings/deposit-requests/$REQ/review" '{"action":"approve"}' "$ADMIN_TOKEN")
check "admin approve setoran -> 200" "$c" "200"

c=$(code PUT "/admin/savings/deposit-requests/$REQ/review" '{"action":"approve"}' "$ADMIN_TOKEN")
check "approve kedua kali -> 422" "$c" "422"

c=$(code GET /savings/accounts "" "$TOKEN")
BAL=$(printf '%s' "$(body)" | sed -n 's/.*"id":'"$ACC"',[^}]*"balance":\([0-9.]*\).*/\1/p')
check "saldo bertambah jadi 5.000.000" "$BAL" "5000000"

# --- Fase 6b: penarikan (withdraw) — perbaikan CACAT-12 ---------------
echo "-- Fase 6b: penarikan dana (CACAT-12)"
c=$(code POST /savings/withdraw "{\"account_id\":$ACC,\"amount\":99000000,\"destination_account\":\"BCA-000111222\"}" "$TOKEN")
check "withdraw melebihi saldo -> 422" "$c" "422"

c=$(code POST /savings/withdraw "{\"account_id\":999999,\"amount\":1000,\"destination_account\":\"BCA-000111222\"}" "$TOKEN")
check "withdraw dari rekening asing -> 404" "$c" "404"

c=$(code POST /savings/withdraw "{\"account_id\":$ACC,\"amount\":2000000,\"destination_account\":\"BCA-000111222\",\"reference_id\":\"WD-SMOKE-$STAMP\"}" "$TOKEN")
check "ajukan withdraw 2.000.000 -> 201" "$c" "201"
WREQ=$(jval "$(body)" id)
check "  status awal pending" "$(jval "$(body)" status)" "pending"

c=$(code GET /savings/withdraw-requests "" "$TOKEN")
check "GET /savings/withdraw-requests -> 200" "$c" "200"

c=$(code PUT "/admin/savings/withdraw-requests/$WREQ/review" '{"action":"approve"}' "$ADMIN_TOKEN")
check "admin approve withdraw -> 200" "$c" "200"

c=$(code PUT "/admin/savings/withdraw-requests/$WREQ/review" '{"action":"approve"}' "$ADMIN_TOKEN")
check "approve kedua kali -> 422" "$c" "422"

c=$(code GET /savings/accounts "" "$TOKEN")
BAL=$(printf '%s' "$(body)" | sed -n 's/.*"id":'"$ACC"',[^}]*"balance":\([0-9.]*\).*/\1/p')
check "saldo berkurang jadi 3.000.000 setelah withdraw" "$BAL" "3000000"

LEDGER=$("$MYSQL" -u root -h 127.0.0.1 koperasi_digital -N -B \
  -e "SELECT COUNT(*) FROM savings_transactions WHERE reference_id='WD-SMOKE-$STAMP' AND type='withdraw';" 2>/dev/null)
check "  ledger 'WD-SMOKE-$STAMP' bertipe withdraw tercatat" "$LEDGER" "1"

c=$(code GET /admin/savings/withdraw-requests "" "$ADMIN_TOKEN")
check "admin GET /admin/savings/withdraw-requests -> 200" "$c" "200"

# --- Fase 3: KYC ------------------------------------------------------
echo "-- Fase 3: KYC"
c=$(code GET /profile/kyc "" "$TOKEN"); check "KYC kosong -> 200 objek kosong" "$c" "200"
c=$(code PUT /profile/kyc "{\"nik\":\"320188$(printf '%010d' "$STAMP")\",\"phone_number\":\"081234567890\",\"address\":\"Jl. Merdeka 1\",\"job_title\":\"Wiraswasta\",\"monthly_income\":7500000,\"emergency_contact_name\":\"Siti\",\"emergency_contact_phone\":\"081298765432\"}" "$TOKEN")
check "simpan KYC -> 200" "$c" "200"
c=$(code PUT /profile/kyc '{"nik":"123","phone_number":"081234567890","address":"x","job_title":"y","monthly_income":1,"emergency_contact_name":"z","emergency_contact_phone":"081234567890"}' "$TOKEN")
check "NIK bukan 16 digit -> 400" "$c" "400"

# --- Fase 4: pembiayaan ----------------------------------------------
echo "-- Fase 4: pembiayaan murabahah"
c=$(code POST /financing/apply '{"principal_amount":12000000,"duration_months":12}' "$TOKEN")
check "apply 12.000.000 / 12 bulan -> 201" "$c" "201"
FIN=$(jval "$(body)" id)
check "  margin_amount = 1.200.000" "$(jval "$(body)" margin_amount)" "1200000"
check "  total_payable = 13.200.000" "$(jval "$(body)" total_payable)" "13200000"

c=$(code POST /financing/apply '{"principal_amount":1000,"duration_months":400}' "$TOKEN")
check "durasi > 360 bulan -> 400" "$c" "400"

c=$(code PUT "/admin/financing/$FIN/review" '{"action":"approve"}' "$ADMIN_TOKEN")
check "admin approve pembiayaan -> 200" "$c" "200"

c=$(code PUT "/admin/financing/$FIN/review" '{"action":"approve"}' "$ADMIN_TOKEN")
check "approve kedua kali -> 409" "$c" "409"

c=$(code GET "/financing/$FIN/installments" "" "$TOKEN")
check "jadwal angsuran -> 200" "$c" "200"
NINS=$(printf '%s' "$(body)" | grep -o '"installment_number"' | wc -l | tr -d ' ')
check "  12 angsuran ter-generate" "$NINS" "12"
SUM=$("$MYSQL" -u root -h 127.0.0.1 koperasi_digital -N -B \
  -e "SELECT CAST(SUM(amount_due) AS CHAR) FROM financing_installments WHERE financing_id=$FIN;" 2>/dev/null)
check "  SUM(amount_due) persis 13.200.000" "$SUM" "13200000.0000"

INS1=$("$MYSQL" -u root -h 127.0.0.1 koperasi_digital -N -B \
  -e "SELECT id FROM financing_installments WHERE financing_id=$FIN ORDER BY installment_number LIMIT 1;" 2>/dev/null)
c=$(code POST "/financing/installments/$INS1/pay" "{\"savings_account_id\":$ACC}" "$TOKEN")
check "bayar angsuran ke-1 -> 200" "$c" "200"
c=$(code POST "/financing/installments/$INS1/pay" "{\"savings_account_id\":$ACC}" "$TOKEN")
check "bayar angsuran yang sama -> 409" "$c" "409"

LEDGER=$("$MYSQL" -u root -h 127.0.0.1 koperasi_digital -N -B \
  -e "SELECT COUNT(*) FROM savings_transactions WHERE reference_id='cicilan_$INS1';" 2>/dev/null)
check "  ledger 'cicilan_$INS1' tercatat" "$LEDGER" "1"

# --- Fase 5: emas -----------------------------------------------------
echo "-- Fase 5: emas digital"
c=$(code GET /gold/price); check "GET /gold/price (publik) -> 200" "$c" "200"
# Harga bisa sudah diubah admin di run sebelumnya, jadi nilai harapan
# diturunkan dari harga yang berlaku SEKARANG, bukan dari angka seed.
BUYP=$(jval "$(body)" buy_price_per_gram)
check "  harga beli terbaca dari DB" "$([ -n "$BUYP" ] && echo yes || echo no)" "yes"
EXP_TOTAL=$("$MYSQL" -u root -h 127.0.0.1 koperasi_digital -N -B \
  -e "SELECT CAST(CAST(buy_price_per_gram * 0.5 AS DECIMAL(19,4)) AS CHAR) FROM gold_prices ORDER BY updated_at DESC, id DESC LIMIT 1;" 2>/dev/null)

c=$(code POST /gold/buy "{\"gram_amount\":101,\"savings_account_id\":$ACC}" "$TOKEN")
check "beli 101 gram -> 400 (BUKAN 500)" "$c" "400"

c=$(code POST /gold/sell "{\"gram_amount\":50,\"savings_account_id\":$ACC}" "$TOKEN")
check "jual tanpa punya emas -> 422 (CACAT-01)" "$c" "422"

c=$(code POST /gold/buy "{\"gram_amount\":0.5,\"savings_account_id\":$ACC}" "$TOKEN")
check "beli 0,5 gram -> 201" "$c" "201"
GTX=$(jval "$(body)" id)
check "  status pending (menunggu mint)" "$(jval "$(body)" status)" "pending"
check "  total_rupiah = 0,5 x harga beli" "$(jval "$(body)" total_rupiah)" "$(printf '%s' "$EXP_TOTAL" | sed 's/\.0*$//')"

DEBIT=$("$MYSQL" -u root -h 127.0.0.1 koperasi_digital -N -B \
  -e "SELECT CAST(amount AS CHAR) FROM savings_transactions WHERE reference_id='gold_buy_$GTX';" 2>/dev/null)
check "  ledger 'gold_buy_$GTX' sama dengan total" "$DEBIT" "$EXP_TOTAL"

c=$(code POST /gold/buy "{\"gram_amount\":100000,\"savings_account_id\":$ACC}" "$TOKEN")
check "beli melebihi saldo -> 400/422" "$([ "$c" = "400" ] || [ "$c" = "422" ] && echo ok || echo "$c")" "ok"

# harga emas oleh admin (CACAT-08)
c=$(code POST /admin/gold/price '{"buy_price_per_gram":1700000,"sell_price_per_gram":1675000}' "$ADMIN_TOKEN")
check "admin set harga emas -> 201" "$c" "201"
c=$(code GET /gold/price)
check "  cache terinvalidasi (harga baru)" "$(jval "$(body)" buy_price_per_gram)" "1700000"
c=$(code POST /admin/gold/price '{"buy_price_per_gram":100,"sell_price_per_gram":200}' "$ADMIN_TOKEN")
check "harga jual > harga beli -> 400" "$c" "400"

# --- logout -----------------------------------------------------------
echo "-- Penutup: logout global"
c=$(code POST /sso/logout "" "$TOKEN"); check "logout -> 200" "$c" "200"
check "  redirect ke end_session JDC Account" "$(jval "$(body)" redirect_url | cut -d'?' -f1)" "$ORIGIN/account/oauth/logout"
c=$(code GET /profile "" "$TOKEN"); check "sesi pasca-logout -> 401" "$c" "401"

rm -f /tmp/body.$$; rm -rf "$TMPD"
echo
printf 'HASIL: \033[32m%d lulus\033[0m, \033[31m%d gagal\033[0m\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
