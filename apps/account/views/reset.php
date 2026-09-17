<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<h1 class="mb-1 font-display text-xl font-semibold text-primary-800">Buat Kata Sandi Baru</h1>
<p class="mb-6 text-sm text-primary-500">Setelah disimpan, semua perangkat yang sedang masuk akan dikeluarkan.</p>

<?php $this->load->view('partials/alerts', array('error' => $error ?? NULL, 'flash' => NULL)); ?>

<?php if ($token !== ''): ?>
<form method="post" action="<?= e($base) ?>/reset-password" class="space-y-5" novalidate>
  <?= $csrf->field() ?>
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <div>
    <label class="label" for="password">Kata Sandi Baru</label>
    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="8" maxlength="72" class="input" autofocus>
  </div>
  <div>
    <label class="label" for="password_confirm">Ulangi Kata Sandi Baru</label>
    <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required class="input">
  </div>
  <button type="submit" class="btn-primary w-full">Simpan Kata Sandi</button>
</form>
<?php else: ?>
  <a href="<?= e($base) ?>/forgot-password" class="btn-primary w-full">Minta Tautan Baru</a>
<?php endif; ?>
