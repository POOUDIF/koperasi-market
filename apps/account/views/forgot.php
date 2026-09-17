<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<h1 class="mb-1 font-display text-xl font-semibold text-primary-800">Lupa Kata Sandi</h1>
<p class="mb-6 text-sm text-primary-500">Masukkan email akun Anda. Kami akan mengirim tautan untuk membuat kata sandi baru.</p>

<?php $this->load->view('partials/alerts', array('error' => $error ?? NULL, 'flash' => $flash ?? NULL)); ?>

<form method="post" action="<?= e($base) ?>/forgot-password" class="space-y-5" novalidate>
  <?= $csrf->field() ?>
  <div>
    <label class="label" for="email">Alamat Email</label>
    <input id="email" name="email" type="email" autocomplete="email" required class="input" placeholder="anda@email.com" autofocus>
  </div>
  <button type="submit" class="btn-primary w-full">Kirim Tautan</button>
</form>

<p class="mt-6 text-center text-xs text-primary-500">
  <a href="<?= e($base) ?>/login" class="font-semibold text-primary-700 hover:underline">Kembali ke halaman masuk</a>
</p>
