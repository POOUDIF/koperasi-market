<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<h1 class="mb-1 font-display text-xl font-semibold text-primary-800"><?= $reauth ? 'Konfirmasi Identitas Anda' : 'Masuk ke Akun Anda' ?></h1>
<p class="mb-6 text-sm text-primary-500">
  <?= $reauth ? 'Demi keamanan, masukkan kembali kata sandi Anda untuk melanjutkan.' : 'Satu akun untuk simpanan, pembiayaan, emas digital, dan marketplace.' ?>
</p>

<?php $this->load->view('partials/alerts', array('error' => $error ?? NULL, 'flash' => $flash ?? NULL)); ?>

<form method="post" action="<?= e($base) ?>/login" class="space-y-5" novalidate>
  <?= $csrf->field() ?>
  <input type="hidden" name="return" value="<?= e($return) ?>">
  <input type="hidden" name="reauth" value="<?= $reauth ? '1' : '0' ?>">

  <div>
    <label class="label" for="email">Alamat Email</label>
    <input id="email" name="email" type="email" autocomplete="username" required class="input"
           placeholder="anda@email.com" value="<?= e($email) ?>" <?= $reauth ? 'readonly' : 'autofocus' ?>>
  </div>

  <div>
    <div class="flex items-center justify-between">
      <label class="label" for="password">Kata Sandi</label>
      <a href="<?= e($base) ?>/forgot-password" class="mb-1.5 text-xs font-semibold text-primary-600 hover:underline">Lupa kata sandi?</a>
    </div>
    <input id="password" name="password" type="password" autocomplete="current-password" required class="input"
           placeholder="Masukkan kata sandi" <?= $reauth ? 'autofocus' : '' ?>>
  </div>

  <?php if ( ! $reauth): ?>
  <label class="flex items-center gap-2 text-sm text-primary-700">
    <input type="checkbox" name="remember" value="1" class="h-4 w-4 rounded border-primary-300 text-primary-700">
    Ingat saya di perangkat ini (30 hari)
  </label>
  <?php endif; ?>

  <button type="submit" class="btn-primary w-full">Masuk</button>
</form>

<?php if ( ! $reauth): ?>
<p class="mt-6 text-center text-xs text-primary-500">
  Belum punya akun?
  <a href="<?= e($base) ?>/register?return=<?= rawurlencode($return) ?>" class="font-semibold text-primary-700 hover:underline">Daftar di sini</a>
</p>
<?php endif; ?>
