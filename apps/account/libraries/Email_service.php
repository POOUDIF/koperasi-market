<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Email transaksional JDC Account.
 *
 * SMTP_HOST kosong → mode simulasi: isi penting ditulis ke log
 * (`[EMAIL SIMULATION] OTP 123456 untuk a@b.c`) — dipakai pengembangan & tes.
 * Kegagalan kirim tidak melempar exception; pengguna selalu bisa minta ulang.
 */
class Email_service {

    public function send_otp($to, $name, $otp) {
        $ttl = (int) (get_instance()->config->item('otp_ttl') / 60);
        return $this->send($to, 'Kode verifikasi akun JDC',
            '<p>Halo ' . e($name) . ',</p>'
            . '<p>Kode verifikasi akun Jawa Dwipa Cooperative Anda:</p>'
            . '<p style="font-size:28px;font-weight:700;letter-spacing:6px;color:#1B4332">' . e($otp) . '</p>'
            . '<p>Kode berlaku ' . $ttl . ' menit. Jangan berikan kode ini kepada siapa pun, termasuk pengurus koperasi.</p>',
            "OTP {$otp} untuk {$to}");
    }

    public function send_reset_link($to, $name, $url) {
        return $this->send($to, 'Atur ulang kata sandi akun JDC',
            '<p>Halo ' . e($name) . ',</p>'
            . '<p>Kami menerima permintaan atur ulang kata sandi. Klik tautan berikut (berlaku 60 menit):</p>'
            . '<p><a href="' . e($url) . '">' . e($url) . '</a></p>'
            . '<p>Abaikan email ini bila Anda tidak memintanya — kata sandi Anda tidak berubah.</p>',
            "RESET {$url} untuk {$to}");
    }

    public function send_password_changed($to, $name) {
        return $this->send($to, 'Kata sandi akun JDC telah diubah',
            '<p>Halo ' . e($name) . ',</p>'
            . '<p>Kata sandi akun Jawa Dwipa Cooperative Anda baru saja diubah dan semua sesi lain telah dikeluarkan.</p>'
            . '<p>Bila ini bukan Anda, segera atur ulang kata sandi dan hubungi pengurus koperasi.</p>',
            "PASSWORD_CHANGED untuk {$to}");
    }

    private function send($to, $subject, $html, $simulation_line) {
        $host = env('SMTP_HOST');

        if (empty($host)) {
            log_message('info', '[EMAIL SIMULATION] ' . $simulation_line);
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
            $m->SMTPSecure = ((int) env('SMTP_PORT', 587) === 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $m->CharSet    = 'UTF-8';
            $m->Timeout    = 10;

            $m->setFrom(env('SMTP_FROM_EMAIL', 'noreply@jdc.shfopis.com'), 'Jawa Dwipa Cooperative');
            $m->addAddress($to);
            $m->isHTML(TRUE);
            $m->Subject = $subject;
            $m->Body    = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#0E241C">' . $html . '</div>';
            $m->AltBody = trim(strip_tags(str_replace('</p>', "</p>\n", $html)));
            $m->send();
            return TRUE;
        } catch (Throwable $e) {
            log_message('error', "[email] gagal mengirim '{$subject}' ke {$to}: " . $e->getMessage());
            return FALSE;
        }
    }
}
