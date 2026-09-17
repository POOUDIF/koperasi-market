#!/usr/bin/env bash
# Ambil OTP/tautan reset terakhir dari log simulasi email JDC Account.
# Pakai:  bash scripts/last-otp.sh <email>
LOGDIR="$(cd "$(dirname "$0")/.." && pwd)/apps/account/logs"
EMAIL="$1"
grep -h "EMAIL SIMULATION" "$LOGDIR"/log-*.php 2>/dev/null | grep -i "$EMAIL" | tail -1
