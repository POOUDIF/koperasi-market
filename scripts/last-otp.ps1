# Ambil OTP/tautan reset terakhir dari log simulasi email JDC Account.
# Pakai:  .\scripts\last-otp.ps1 email@contoh.com
param([Parameter(Mandatory=$true)][string]$Email)

$logDir = Join-Path $PSScriptRoot "..\apps\account\logs"
$latest = Get-ChildItem $logDir -Filter "log-*.php" |
    Sort-Object LastWriteTime -Descending |
    Select-Object -First 1

if (-not $latest) {
    Write-Host "Tidak ada file log di $logDir" -ForegroundColor Red
    exit 1
}

$pattern = [Regex]::Escape($Email)
$match = Get-Content $latest.FullName |
    Where-Object { $_ -match "EMAIL SIMULATION" -and $_ -match $pattern } |
    Select-Object -Last 1

if (-not $match) {
    Write-Host "Tidak ditemukan entri untuk $Email" -ForegroundColor Yellow
    exit 1
}

$match
