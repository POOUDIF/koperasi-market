<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<h1 class="mb-2 font-display text-xl font-semibold text-primary-800"><?= e($heading) ?></h1>
<p class="mb-6 text-sm text-primary-600"><?= e($message) ?></p>
<div class="flex gap-3">
  <a href="<?= e($app_url) ?>/" class="btn-secondary flex-1">Beranda JDC</a>
  <a href="<?= e($base) ?>" class="btn-primary flex-1">Akun Saya</a>
</div>
