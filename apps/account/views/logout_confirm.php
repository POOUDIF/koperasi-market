<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<h1 class="mb-2 font-display text-xl font-semibold text-primary-800">Keluar dari semua layanan JDC?</h1>
<p class="mb-6 text-sm text-primary-600">
  Anda masuk sebagai <strong><?= e($user['email']) ?></strong>. Keluar akan mengakhiri sesi di Koperasi Digital dan Marketplace pada perangkat ini.
</p>
<form method="post" action="<?= e($base) ?>/oauth/logout" class="flex gap-3">
  <?= $csrf->field() ?>
  <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
  <input type="hidden" name="state" value="<?= e($state) ?>">
  <a href="<?= e($base) ?>" class="btn-secondary flex-1">Batal</a>
  <button type="submit" class="btn-danger flex-1">Ya, Keluar</button>
</form>
