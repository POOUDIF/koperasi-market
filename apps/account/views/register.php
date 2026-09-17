<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<h1 class="mb-1 font-display text-xl font-semibold text-primary-800">Buat Akun JDC</h1>
<p class="mb-6 text-sm text-primary-500">Daftar sekali, gunakan untuk Koperasi Digital dan Marketplace.</p>

<?php $this->load->view('partials/alerts', array('error' => $error ?? NULL, 'flash' => NULL)); ?>

<form method="post" action="<?= e($base) ?>/register" class="space-y-5" novalidate>
  <?= $csrf->field() ?>
  <input type="hidden" name="return" value="<?= e($return) ?>">

  <div>
    <label class="label" for="name">Nama Lengkap (sesuai KTP)</label>
    <input id="name" name="name" type="text" autocomplete="name" required minlength="3" maxlength="150" class="input"
           placeholder="Nama lengkap" value="<?= e($old['name'] ?? '') ?>" autofocus>
  </div>
  <div>
    <label class="label" for="email">Alamat Email</label>
    <input id="email" name="email" type="email" autocomplete="email" required class="input"
           placeholder="anda@email.com" value="<?= e($old['email'] ?? '') ?>">
  </div>
  <div>
    <label class="label" for="password">Kata Sandi</label>
    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="8" maxlength="72" class="input"
           placeholder="Minimal 8 karakter">
    <p class="mt-1 text-xs text-primary-400">Gunakan minimal 8 karakter yang sulit ditebak. Jangan gunakan ulang kata sandi dari situs lain.</p>
  </div>
  <div>
    <label class="label" for="password_confirm">Ulangi Kata Sandi</label>
    <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required class="input"
           placeholder="Ketik ulang kata sandi">
  </div>
  <label class="flex items-start gap-2 text-sm text-primary-700">
    <input type="checkbox" name="agree" value="1" required class="mt-0.5 h-4 w-4 rounded border-primary-300">
    <span>Saya menyetujui <a href="<?= e($app_url) ?>/syarat-ketentuan/" class="font-semibold underline" target="_blank" rel="noopener">syarat &amp; ketentuan</a>
      serta <a href="<?= e($app_url) ?>/kebijakan-privasi/" class="font-semibold underline" target="_blank" rel="noopener">kebijakan privasi</a>.</span>
  </label>

  <button type="submit" class="btn-primary w-full">Daftar</button>
</form>

<p class="mt-6 text-center text-xs text-primary-500">
  Sudah punya akun?
  <a href="<?= e($base) ?>/login?return=<?= rawurlencode($return) ?>" class="font-semibold text-primary-700 hover:underline">Masuk</a>
</p>
