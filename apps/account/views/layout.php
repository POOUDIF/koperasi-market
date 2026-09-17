<?php defined('BASEPATH') OR exit('No direct script access allowed');
/** @var string $title @var string $content @var string $base @var string $app_url */
$layout = $layout ?? 'auth';
?><!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($title) ?> — Akun JDC</title>
  <link rel="icon" type="image/svg+xml" href="<?= e($base) ?>/assets/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@400;500;600;700&display=swap">
  <link rel="stylesheet" href="<?= e($base) ?>/assets/app.css">
</head>
<?php if ($layout === 'app'): ?>
<body class="min-h-screen bg-cream-100 text-primary-900 font-sans antialiased">
  <header class="border-b border-primary-100 bg-white">
    <div class="mx-auto flex h-16 max-w-5xl items-center justify-between px-4">
      <a href="<?= e($app_url) ?>/" class="flex items-center gap-2.5">
        <?php $this->load->view('partials/logo', array('size' => 34)); ?>
        <span class="leading-tight">
          <span class="block font-display text-sm font-bold text-primary-800">Jawa Dwipa</span>
          <span class="block text-[10px] font-semibold tracking-widest text-gold-600">AKUN JDC</span>
        </span>
      </a>
      <nav class="flex items-center gap-2 text-sm">
        <a class="rounded-lg px-3 py-2 font-medium text-primary-700 hover:bg-primary-50" href="<?= e($base) ?>">Akun Saya</a>
        <?php if ( ! empty($is_admin)): ?>
          <a class="rounded-lg px-3 py-2 font-medium text-secondary-700 hover:bg-secondary-50" href="<?= e($base) ?>/admin/users">Admin</a>
        <?php endif; ?>
        <form method="post" action="<?= e($base) ?>/logout">
          <?= $csrf->field() ?>
          <button type="submit" class="btn-secondary">Keluar</button>
        </form>
      </nav>
    </div>
  </header>
  <main class="mx-auto max-w-5xl px-4 py-8"><?= $content ?></main>
</body>
<?php else: ?>
<body class="min-h-screen bg-primary-800 font-sans antialiased">
  <main class="auth-bg flex min-h-screen items-center justify-center p-4">
    <div class="w-full max-w-md">
      <div class="mb-8 text-center">
        <a href="<?= e($app_url) ?>/" class="mb-4 inline-flex drop-shadow-lg" aria-label="Beranda Jawa Dwipa Cooperative">
          <?php $this->load->view('partials/logo', array('size' => 72)); ?>
        </a>
        <p class="font-display text-2xl font-bold text-white">Jawa Dwipa Cooperative</p>
        <p class="mt-1 text-sm tracking-wide text-primary-100/80">TOGETHER &middot; IN ACTION &middot; FOR PROSPERITY</p>
      </div>
      <div class="rounded-2xl bg-white p-8 shadow-2xl"><?= $content ?></div>
      <p class="mt-6 text-center text-xs text-primary-100/70">
        Satu akun untuk Koperasi Digital &amp; Marketplace JDC.<br>
        &copy; <?= date('Y') ?> Koperasi Digital Syariah Jawa Dwipa.
      </p>
    </div>
  </main>
</body>
<?php endif; ?>
</html>
