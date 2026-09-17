#!/usr/bin/env bash
# =====================================================================
# Helper uji bersama: login lewat JDC Account seperti browser sungguhan
# (form HTML + CSRF + OTP dari log simulasi email + redirect OIDC).
#
# Tidak ada "backdoor" sesi uji: skrip melewati alur yang sama persis
# dengan pengguna, jadi uji ini sekaligus membuktikan alur SSO bekerja.
#
#   source tests/lib/jdc.sh
# =====================================================================

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ORIGIN="${ORIGIN:-http://127.0.0.1:8300}"
ACCOUNT_LOGDIR="$ROOT/apps/account/logs"
MYSQL_BIN="${MYSQL_BIN:-mysql}"
TMPD="${TMPD:-$(mktemp -d)}"

PASS=${PASS:-0}; FAIL=${FAIL:-0}

check() { # check <label> <actual> <expected>
  if [ "$2" = "$3" ]; then printf '  \033[32mOK\033[0m   %-62s %s\n' "$1" "$2"; PASS=$((PASS+1))
  else printf '  \033[31mFAIL\033[0m %-62s got=%s want=%s\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}

# jval <json> <key> — ekstrak nilai skalar pertama tanpa jq
jval() { printf '%s' "$1" | sed -n "s/.*\"$2\"[[:space:]]*:[[:space:]]*\"\{0,1\}\([^,\"}]*\)\"\{0,1\}.*/\1/p" | head -1; }

csrf_of() { sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' "$1" | head -1; }

# OTP terakhir untuk email dari log simulasi email IdP
otp_for() {
  grep -h "EMAIL SIMULATION" "$ACCOUNT_LOGDIR"/log-*.php 2>/dev/null | grep "OTP [0-9]\{6\} untuk $1\"" \
    | tail -1 | sed -n 's/.*OTP \([0-9]\{6\}\) untuk.*/\1/p'
}
reset_link_for() {
  grep -h "EMAIL SIMULATION" "$ACCOUNT_LOGDIR"/log-*.php 2>/dev/null | grep "RESET .* untuk $1\"" \
    | tail -1 | sed -n 's/.*RESET \([^ ]*\) untuk.*/\1/p'
}

# c <jar> <curl args...>  — curl dengan cookie jar; body ke $TMPD/body, echo status
c() {
  local jar="$1"; shift
  curl -s -b "$jar" -c "$jar" -o "$TMPD/body" -D "$TMPD/headers" -w '%{http_code}' "$@"
}
body()     { cat "$TMPD/body"; }
location() { tr -d '\r' < "$TMPD/headers" | sed -n 's/^[Ll]ocation: //p' | tail -1; }

# api <jar> <method> <url> [json]  — panggilan XHR dari SPA (header CSRF ikut)
api() {
  local jar="$1" m="$2" url="$3" data="${4:-}"
  local args=(-X "$m" "$url" -H 'X-Requested-With: XMLHttpRequest' -H 'Accept: application/json')
  [ -n "$data" ] && args+=(-H 'Content-Type: application/json' -d "$data")
  c "$jar" "${args[@]}"
}

# idp_register <jar> <name> <email> <password>  → akun terverifikasi + sesi IdP di jar
idp_register() {
  local jar="$1" name="$2" email="$3" pw="$4"
  c "$jar" "$ORIGIN/account/register" >/dev/null
  local t; t=$(csrf_of "$TMPD/body")
  c "$jar" -X POST "$ORIGIN/account/register" --data-urlencode "_csrf=$t" --data-urlencode "name=$name" \
    --data-urlencode "email=$email" --data-urlencode "password=$pw" --data-urlencode "password_confirm=$pw" \
    --data-urlencode "agree=1" --data-urlencode "return=/account" >/dev/null
  local otp; otp=$(otp_for "$email")
  c "$jar" -X POST "$ORIGIN/account/verify-email" --data-urlencode "_csrf=$t" --data-urlencode "email=$email" \
    --data-urlencode "otp=$otp" --data-urlencode "return=/account" --data-urlencode "remember=0"
}

# idp_login <jar> <email> <password>  → echo status HTTP POST login
idp_login() {
  local jar="$1" email="$2" pw="$3"
  c "$jar" "$ORIGIN/account/login" >/dev/null
  local t; t=$(csrf_of "$TMPD/body")
  c "$jar" -X POST "$ORIGIN/account/login" --data-urlencode "_csrf=$t" --data-urlencode "email=$email" \
    --data-urlencode "password=$pw" --data-urlencode "return=/account"
}

# app_login <jar> <koperasi|market> [prompt]  → ikuti alur OIDC, echo URL akhir
# Butuh sesi IdP di jar (idp_register / idp_login).
app_login() {
  local jar="$1" app="$2" prompt="${3:-}"
  local q="return_to=/$app/dashboard"
  [ "$app" = "market" ] && q="return_to=/market/"
  [ -n "$prompt" ] && q="$q&prompt=$prompt"
  curl -s -L -b "$jar" -c "$jar" -o /dev/null -w '%{url_effective}' --max-redirs 10 \
    "$ORIGIN/$app/api/v1/sso/login?$q"
}

sql() { # sql <db> <query>
  "$MYSQL_BIN" -u root -h 127.0.0.1 -N -B "$1" -e "$2" 2>/dev/null
}
