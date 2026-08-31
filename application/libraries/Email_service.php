<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Padanan smtpEmailService.SendOTPEmail (§10.12).
 *
 * SMTP_HOST kosong → mode simulasi (tulis OTP ke log), TIDAK melempar error.
 * Kegagalan kirim email TIDAK BOLEH menggagalkan registrasi — anggota sudah
 * tersimpan di DB, dan OTP sudah ada di Redis.
 */
class Email_service {

    public function send_otp($to, $otp) {
        $host = env('SMTP_HOST');

        if (empty($host)) {
            log_message('info', "[EMAIL SIMULATION] OTP {$otp} untuk {$to}");
            return TRUE;
        }

        try {
            $m = new PHPMailer(TRUE);
            $m->isSMTP();
            $m->Host       = $host;
            $m->Port       = (int) env('SMTP_PORT', 587);
            $m->SMTPAuth   = TRUE;
            $m->Username   = env('SMTP_USER');
            $m->Password   = env('SMTP_PASSWORD');
            $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $m->CharSet    = 'UTF-8';
            $m->Timeout    = 10;

            $m->setFrom(env('SMTP_FROM_EMAIL', 'noreply@koperasi-digital.com'), 'Koperasi Digital');
            $m->addAddress($to);
            $m->isHTML(TRUE);
            $m->Subject = 'Kode Verifikasi Koperasi Digital';
            $m->Body    = '<h2>Selamat Datang di Koperasi Digital</h2>'
                        . '<p>Berikut adalah kode verifikasi OTP Anda:</p>'
                        . '<h1 style="color:blue;">' . htmlspecialchars($otp) . '</h1>'
                        . '<p>Kode ini berlaku selama 15 menit. Jangan berikan kode ini kepada siapa pun.</p>';
            $m->send();
            return TRUE;

        } catch (Throwable $e) {
            log_message('error', "[email] gagal mengirim OTP ke {$to}: " . $e->getMessage());
            return FALSE;   // non-fatal, sama seperti kode Go
        }
    }
}
