#!/usr/bin/env bash
# =====================================================================
# Verifikasi manual §22 — dijalankan berurutan, tiap langkah bergantung
# pada hasil sebelumnya.
#
#   bash tests/smoke_test.sh [BASE_URL]
#
# Prasyarat: MySQL + Redis jalan, skema & seed sudah diterapkan,
# SMTP_HOST kosong (OTP muncul di application/logs).
# =====================================================================
set -u

BASE="${1:-http://127.0.0.1:8099/api/v1}"
MYSQL="C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe"
LOGDIR="$(cd "$(dirname "$0")/.." && pwd)/application/logs"

PASS=0; FAIL=0
STAMP=$(date +%s)
MEMBER="budi${STAMP}@mail.com"
ADMIN="admin${STAMP}@mail.com"
PW="rahasia123"

# --- util -------------------------------------------------------------
# jval <json> <key>  — ekstrak nilai skalar tanpa jq
jval() { printf '%s' "$1" | sed -n "s/.*\"$2\"[[:space:]]*:[[:space:]]*\"\{0,1\}\([^,\"}]*\)\"\{0,1\}.*/\1/p" | head -1; }

check() { # check <label> <actual> <expected>
  if [ "$2" = "$3" ]; then printf '  \033[32mOK\033[0m   %-58s %s\n' "$1" "$2"; PASS=$((PASS+1))
  else printf '  \033[31mFAIL\033[0m %-58s got=%s want=%s\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}

code() { # code <method> <path> [json] [token]
  local m="$1" p="$2" d="${3:-}" t="${4:-}"
  local args=(-s -o /tmp/body.$$ -w '%{http_code}' -X "$m" "$BASE$p" -H 'Content-Type: application/json')
  [ -n "$t" ] && args+=(-H "Authorization: Bearer $t")
  [ -n "$d" ] && args+=(-d "$d")
  curl "${args[@]}"
}
body() { cat /tmp/body.$$; }

otp_for() { # ambil OTP terakhir untuk sebuah email dari log simulasi email
  grep -h "EMAIL SIMULATION" "$LOGDIR"/log-*.php 2>/dev/null \
    | grep "$1" | tail -1 | sed -n 's/.*OTP \([0-9]\{6\}\).*/\1/p'
}

echo "== BASE: $BASE"

# --- 1. Health --------------------------------------------------------
echo "-- Fase 0: fondasi"
c=$(code GET /health); check "GET /health" "$c" "200"
check "  services.database" "$(jval "$(body)" database)" "ok"
check "  services.redis"    "$(jval "$(body)" redis)"    "ok"
c=$(code GET /tidak-ada); check "route tak dikenal -> 404 JSON" "$c" "404"

# --- 2..7 Auth --------------------------------------------------------
echo "-- Fase 1: autentikasi"
c=$(code POST /register "{\"nama_lengkap\":\"Budi Santoso\",\"email\":\"$MEMBER\",\"password\":\"$PW\"}")
check "register anggota" "$c" "201"
UID_MEMBER=$(jval "$(body)" user_id)

c=$(code POST /register "{\"nama_lengkap\":\"Budi Santoso\",\"email\":\"$MEMBER\",\"password\":\"$PW\"}")
check "register email duplikat -> 409" "$c" "409"

c=$(code POST /register "{\"nama_lengkap\":\"Bu\",\"email\":\"x@y.co\",\"password\":\"$PW\"}")
check "nama < 3 karakter -> 400" "$c" "400"

c=$(code POST /register "{\"nama_lengkap\":\"Cukup Panjang\",\"email\":\"x@y.co\",\"password\":\"pendek\"}")
check "password < 8 karakter -> 400" "$c" "400"

c=$(code POST /login "{\"email\":\"$MEMBER\",\"password\":\"$PW\"}")
check "login sebelum verifikasi -> 403" "$c" "403"

# resend-otp (usulan perbaikan CACAT-06, belum ada di blueprint asli)
FIRST_OTP=$(otp_for "$MEMBER")
c=$(code POST /resend-otp "{\"email\":\"$MEMBER\"}")
check "resend-otp email terdaftar -> 200" "$c" "200"
c=$(code POST /resend-otp '{"email":"tidak-terdaftar-xyz@mail.com"}')
check "resend-otp email tidak terdaftar -> tetap 200 (anti-enumerasi)" "$c" "200"
c=$(code POST /verify-email "{\"email\":\"$MEMBER\",\"otp\":\"$FIRST_OTP\"}")
check "  OTP lama tertimpa oleh resend -> 400" "$c" "400"

OTP=$(otp_for "$MEMBER")
check "OTP tersimulasi di log (6 digit)" "$(printf '%s' "$OTP" | wc -c | tr -d ' ')" "6"

c=$(code POST /verify-email "{\"email\":\"$MEMBER\",\"otp\":\"000000\"}")
check "OTP salah -> 400" "$c" "400"

c=$(code POST /verify-email "{\"email\":\"$MEMBER\",\"otp\":\"$OTP\"}")
check "verify-email -> 200" "$c" "200"
TOKEN=$(jval "$(body)" token)
check "  token terbit" "$([ -n "$TOKEN" ] && echo yes || echo no)" "yes"
check "  password_hash TIDAK bocor" "$(printf '%s' "$(body)" | grep -c password_hash)" "0"

c=$(code POST /verify-email "{\"email\":\"$MEMBER\",\"otp\":\"$OTP\"}")
check "OTP sekali pakai -> 400" "$c" "400"

c=$(code GET /profile "" "$TOKEN"); check "GET /profile" "$c" "200"
check "  password_hash TIDAK bocor" "$(printf '%s' "$(body)" | grep -c password_hash)" "0"

c=$(code GET /profile "" "token-palsu"); check "token palsu -> 401" "$c" "401"
c=$(code GET /profile); check "tanpa header Authorization -> 401" "$c" "401"

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

# admin: daftar, verifikasi email, lalu naikkan role lewat SQL
c=$(code POST /register "{\"nama_lengkap\":\"Admin Koperasi\",\"email\":\"$ADMIN\",\"password\":\"$PW\"}")
AOTP=$(otp_for "$ADMIN")
code POST /verify-email "{\"email\":\"$ADMIN\",\"otp\":\"$AOTP\"}" >/dev/null
ADMIN_TOKEN=$(jval "$(body)" token)

c=$(code GET /admin/users "" "$TOKEN"); check "anggota akses /admin/users -> 403" "$c" "403"

"$MYSQL" -u root -h 127.0.0.1 koperasi_digital \
  -e "UPDATE users SET role='super_admin' WHERE email='$ADMIN';" 2>/dev/null

c=$(code POST /login "{\"email\":\"$ADMIN\",\"password\":\"$PW\"}")
ADMIN_TOKEN=$(jval "$(body)" token)
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

c=$(code POST /savings/withdraw "{\"account_id\":$ACC,\"amount\":2000000,\"destination_account\":\"BCA-000111222\",\"reference_id\":\"WD-SMOKE-1\"}" "$TOKEN")
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
  -e "SELECT COUNT(*) FROM savings_transactions WHERE reference_id='WD-SMOKE-1' AND type='withdraw';" 2>/dev/null)
check "  ledger 'WD-SMOKE-1' bertipe withdraw tercatat" "$LEDGER" "1"

c=$(code GET /admin/savings/withdraw-requests "" "$ADMIN_TOKEN")
check "admin GET /admin/savings/withdraw-requests -> 200" "$c" "200"

# --- Fase 3: KYC ------------------------------------------------------
echo "-- Fase 3: KYC"
c=$(code GET /profile/kyc "" "$TOKEN"); check "KYC kosong -> 200 objek kosong" "$c" "200"
c=$(code PUT /profile/kyc "{\"nik\":\"320123456789012${STAMP: -1}\",\"phone_number\":\"081234567890\",\"address\":\"Jl. Merdeka 1\",\"job_title\":\"Wiraswasta\",\"monthly_income\":7500000,\"emergency_contact_name\":\"Siti\",\"emergency_contact_phone\":\"081298765432\"}" "$TOKEN")
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
echo "-- Penutup: logout & blocklist"
c=$(code POST /logout "" "$TOKEN"); check "logout -> 200" "$c" "200"
c=$(code GET /profile "" "$TOKEN"); check "token pasca-logout -> 401" "$c" "401"

rm -f /tmp/body.$$
echo
printf 'HASIL: \033[32m%d lulus\033[0m, \033[31m%d gagal\033[0m\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
