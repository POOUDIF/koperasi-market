#!/usr/bin/env bash
# =====================================================================
# Uji konkurensi §22 — WAJIB sebelum go-live.
#
# Dua request identik ditembakkan BERSAMAAN ke dua instance server yang
# berbeda (php -S single-threaded, jadi satu instance akan menyerialkan
# request dan uji ini jadi tidak berarti).
#
#   bash tests/concurrency_test.sh [PORT_A] [PORT_B]
#
# Harapan di setiap kasus: tepat SATU request berhasil, saldo berubah
# TEPAT SEKALI. Kalau dua-duanya berhasil, row locking tidak bekerja.
# =====================================================================
set -u

PA="${1:-8099}"; PB="${2:-8098}"
A="http://127.0.0.1:$PA/api/v1"; B="http://127.0.0.1:$PB/api/v1"
MYSQL="C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe"
LOGDIR="$(cd "$(dirname "$0")/.." && pwd)/application/logs"

PASS=0; FAIL=0
STAMP=$(date +%s)
MEMBER="conc${STAMP}@mail.com"; ADMIN="cadm${STAMP}@mail.com"; PW="rahasia123"

jval() { printf '%s' "$1" | sed -n "s/.*\"$2\"[[:space:]]*:[[:space:]]*\"\{0,1\}\([^,\"}]*\)\"\{0,1\}.*/\1/p" | head -1; }
sql()  { "$MYSQL" -u root -h 127.0.0.1 koperasi_digital -N -B -e "$1" 2>/dev/null; }
check(){ if [ "$2" = "$3" ]; then printf '  \033[32mOK\033[0m   %-52s %s\n' "$1" "$2"; PASS=$((PASS+1));
         else printf '  \033[31mFAIL\033[0m %-52s got=%s want=%s\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi }

req() { # req <base> <method> <path> <json> <token> <outfile>
  local args=(-s -o /dev/null -w '%{http_code}' -X "$2" "$1$3" -H 'Content-Type: application/json')
  [ -n "$5" ] && args+=(-H "Authorization: Bearer $5")
  [ -n "$4" ] && args+=(-d "$4")
  curl "${args[@]}" > "$6"
}

post() { # post <base> <method> <path> <json> <token>  -> body ke /tmp/cb.$$
  local args=(-s -o "/tmp/cb.$$" -w '%{http_code}' -X "$2" "$1$3" -H 'Content-Type: application/json')
  [ -n "${5:-}" ] && args+=(-H "Authorization: Bearer $5")
  [ -n "${4:-}" ] && args+=(-d "$4")
  curl "${args[@]}"
}
cbody() { cat "/tmp/cb.$$"; }
otp_for() { grep -h "EMAIL SIMULATION" "$LOGDIR"/log-*.php 2>/dev/null | grep "$1" | tail -1 | sed -n 's/.*OTP \([0-9]\{6\}\).*/\1/p'; }

# --- siapkan anggota + admin + saldo ----------------------------------
echo "== menyiapkan data uji (server A=$PA, B=$PB)"
post "$A" POST /register "{\"nama_lengkap\":\"Concurrency Tester\",\"email\":\"$MEMBER\",\"password\":\"$PW\"}" >/dev/null
post "$A" POST /verify-email "{\"email\":\"$MEMBER\",\"otp\":\"$(otp_for "$MEMBER")\"}" >/dev/null
TOKEN=$(jval "$(cbody)" token)

post "$A" POST /register "{\"nama_lengkap\":\"Concurrency Admin\",\"email\":\"$ADMIN\",\"password\":\"$PW\"}" >/dev/null
post "$A" POST /verify-email "{\"email\":\"$ADMIN\",\"otp\":\"$(otp_for "$ADMIN")\"}" >/dev/null
sql "UPDATE users SET role='super_admin' WHERE email='$ADMIN';"
post "$A" POST /login "{\"email\":\"$ADMIN\",\"password\":\"$PW\"}" >/dev/null
ADMIN_TOKEN=$(jval "$(cbody)" token)

post "$A" GET /savings/accounts "" "$TOKEN" >/dev/null
ACC=$(jval "$(cbody)" id)

# Isi saldo lewat jalur resmi: ajukan + approve.
post "$A" POST /savings/deposit "{\"account_id\":$ACC,\"amount\":50000000,\"payment_method\":\"manual_transfer\"}" "$TOKEN" >/dev/null
REQ=$(jval "$(cbody)" id)
post "$A" PUT "/admin/savings/deposit-requests/$REQ/review" '{"action":"approve"}' "$ADMIN_TOKEN" >/dev/null
echo "   anggota=$MEMBER rekening=$ACC saldo=$(sql "SELECT CAST(balance AS CHAR) FROM savings_accounts WHERE id=$ACC;")"

# =====================================================================
echo "-- Kasus 1: dua approve setoran yang sama, bersamaan"
post "$A" POST /savings/deposit "{\"account_id\":$ACC,\"amount\":1000000,\"payment_method\":\"manual_transfer\"}" "$TOKEN" >/dev/null
R1=$(jval "$(cbody)" id)
BEFORE=$(sql "SELECT CAST(balance AS CHAR) FROM savings_accounts WHERE id=$ACC;")

req "$A" PUT "/admin/savings/deposit-requests/$R1/review" '{"action":"approve"}' "$ADMIN_TOKEN" /tmp/r1a.$$ &
req "$B" PUT "/admin/savings/deposit-requests/$R1/review" '{"action":"approve"}' "$ADMIN_TOKEN" /tmp/r1b.$$ &
wait
CODES=$(printf '%s %s' "$(cat /tmp/r1a.$$)" "$(cat /tmp/r1b.$$)")
OK200=$(printf '%s\n' $CODES | grep -c '^200$')
AFTER=$(sql "SELECT CAST(balance AS CHAR) FROM savings_accounts WHERE id=$ACC;")
DELTA=$(printf '%s' "$(sql "SELECT CAST($AFTER - $BEFORE AS CHAR);")")

check "tepat satu approve berhasil        [$CODES]" "$OK200" "1"
check "saldo bertambah TEPAT SEKALI"                "$DELTA" "1000000.0000"
check "ledger deposit hanya 1 baris"                "$(sql "SELECT COUNT(*) FROM savings_transactions WHERE savings_account_id=$ACC AND type='deposit' AND amount=1000000;")" "1"

# =====================================================================
echo "-- Kasus 2: dua pembayaran angsuran yang sama, bersamaan"
post "$A" POST /financing/apply '{"principal_amount":1200000,"duration_months":3}' "$TOKEN" >/dev/null
FIN=$(jval "$(cbody)" id)
post "$A" PUT "/admin/financing/$FIN/review" '{"action":"approve"}' "$ADMIN_TOKEN" >/dev/null
INS=$(sql "SELECT id FROM financing_installments WHERE financing_id=$FIN ORDER BY installment_number LIMIT 1;")
DUE=$(sql "SELECT CAST(amount_due AS CHAR) FROM financing_installments WHERE id=$INS;")
BEFORE=$(sql "SELECT CAST(balance AS CHAR) FROM savings_accounts WHERE id=$ACC;")

req "$A" POST "/financing/installments/$INS/pay" "{\"savings_account_id\":$ACC}" "$TOKEN" /tmp/r2a.$$ &
req "$B" POST "/financing/installments/$INS/pay" "{\"savings_account_id\":$ACC}" "$TOKEN" /tmp/r2b.$$ &
wait
CODES=$(printf '%s %s' "$(cat /tmp/r2a.$$)" "$(cat /tmp/r2b.$$)")
OK200=$(printf '%s\n' $CODES | grep -c '^200$')
AFTER=$(sql "SELECT CAST(balance AS CHAR) FROM savings_accounts WHERE id=$ACC;")
DELTA=$(sql "SELECT CAST($BEFORE - $AFTER AS CHAR);")

check "tepat satu pembayaran berhasil     [$CODES]" "$OK200" "1"
check "saldo berkurang TEPAT SEKALI"                "$DELTA" "$DUE"
check "ledger cicilan hanya 1 baris"                "$(sql "SELECT COUNT(*) FROM savings_transactions WHERE reference_id='cicilan_$INS';")" "1"

# =====================================================================
echo "-- Kasus 3: dua pembelian emas bersamaan dari saldo yang hampir habis"
# Sisakan saldo hanya cukup untuk SATU pembelian 1 gram.
PRICE=$(sql "SELECT CAST(buy_price_per_gram AS CHAR) FROM gold_prices ORDER BY updated_at DESC, id DESC LIMIT 1;")
sql "UPDATE savings_accounts SET balance = $PRICE WHERE id = $ACC;"

req "$A" POST /gold/buy "{\"gram_amount\":1,\"savings_account_id\":$ACC}" "$TOKEN" /tmp/r3a.$$ &
req "$B" POST /gold/buy "{\"gram_amount\":1,\"savings_account_id\":$ACC}" "$TOKEN" /tmp/r3b.$$ &
wait
CODES=$(printf '%s %s' "$(cat /tmp/r3a.$$)" "$(cat /tmp/r3b.$$)")
OK201=$(printf '%s\n' $CODES | grep -c '^201$')
BAL=$(sql "SELECT CAST(balance AS CHAR) FROM savings_accounts WHERE id=$ACC;")

check "tepat satu pembelian berhasil      [$CODES]" "$OK201" "1"
check "saldo tidak pernah negatif"                  "$(sql "SELECT IF($BAL >= 0,'ya','TIDAK');")" "ya"
check "saldo tersisa 0 (didebet sekali)"            "$BAL" "0.0000"

# =====================================================================
echo "-- Kasus 4: dua penjualan emas bersamaan atas kepemilikan yang sama"
# Beri anggota 1 gram emas yang benar-benar 'success'.
sql "UPDATE gold_transactions SET status='success' WHERE user_id=(SELECT id FROM users WHERE email='$MEMBER') AND type='buy';"
HOLD=$(sql "SELECT CAST(COALESCE(SUM(CASE WHEN type='buy' THEN gram_amount ELSE -gram_amount END),0) AS CHAR) FROM gold_transactions WHERE user_id=(SELECT id FROM users WHERE email='$MEMBER') AND status='success';")
echo "   kepemilikan emas: $HOLD gram"

req "$A" POST /gold/sell "{\"gram_amount\":$HOLD,\"savings_account_id\":$ACC}" "$TOKEN" /tmp/r4a.$$ &
req "$B" POST /gold/sell "{\"gram_amount\":$HOLD,\"savings_account_id\":$ACC}" "$TOKEN" /tmp/r4b.$$ &
wait
CODES=$(printf '%s %s' "$(cat /tmp/r4a.$$)" "$(cat /tmp/r4b.$$)")
OK201=$(printf '%s\n' $CODES | grep -c '^201$')
NET=$(sql "SELECT CAST(COALESCE(SUM(CASE WHEN type='buy' THEN gram_amount ELSE -gram_amount END),0) AS CHAR) FROM gold_transactions WHERE user_id=(SELECT id FROM users WHERE email='$MEMBER') AND status='success';")

check "tepat satu penjualan berhasil      [$CODES]" "$OK201" "1"
check "kepemilikan emas tidak negatif"              "$(sql "SELECT IF($NET >= 0,'ya','TIDAK');")" "ya"

rm -f /tmp/r1a.$$ /tmp/r1b.$$ /tmp/r2a.$$ /tmp/r2b.$$ /tmp/r3a.$$ /tmp/r3b.$$ /tmp/r4a.$$ /tmp/r4b.$$ /tmp/cb.$$
echo
printf 'HASIL KONKURENSI: \033[32m%d lulus\033[0m, \033[31m%d gagal\033[0m\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
