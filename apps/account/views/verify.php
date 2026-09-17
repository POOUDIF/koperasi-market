<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<h1 class="mb-1 font-display text-xl font-semibold text-primary-800">Verifikasi Email</h1>
<p class="mb-6 text-sm text-primary-500">
  Kami mengirim kode 6 digit ke <strong class="text-primary-800"><?= e($email) ?></strong>. Periksa juga folder spam.
</p>

<?php $this->load->view('partials/alerts', array('error' => $error ?? NULL, 'flash' => $flash ?? NULL)); ?>

<form method="post" action="<?= e($base) ?>/verify-email" class="space-y-5" novalidate>
  <?= $csrf->field() ?>
  <input type="hidden" name="email" value="<?= e($email) ?>">
  <input type="hidden" name="return" value="<?= e($return) ?>">
  <input type="hidden" name="remember" value="<?= $remember ? '1' : '0' ?>">
  <div>
    <label class="label" for="otp">Kode Verifikasi</label>
    <input id="otp" name="otp" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required
           class="input text-center text-2xl font-semibold tracking-[0.5em]" placeholder="••••••" autofocus>
  </div>
  <button type="submit" class="btn-primary w-full">Verifikasi &amp; Masuk</button>
</form>

<form method="post" action="<?= e($base) ?>/resend-otp" class="mt-6 text-center">
  <?= $csrf->field() ?>
  <input type="hidden" name="email" value="<?= e($email) ?>">
  <input type="hidden" name="return" value="<?= e($return) ?>">
  <span class="text-xs text-primary-500">Tidak menerima kode?</span>
  <button type="submit" class="text-xs font-semibold text-primary-700 hover:underline">Kirim ulang</button>
</form>
