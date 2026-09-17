<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="mb-8">
  <p class="text-sm font-semibold uppercase tracking-wider text-gold-700">Akun Saya</p>
  <h1 class="mt-1 font-display text-2xl font-bold text-primary-800">Halo, <?= e($user['name']) ?></h1>
  <p class="text-sm text-primary-500"><?= e($user['email']) ?></p>
</div>

<?php $this->load->view('partials/alerts', array('error' => $error ?? NULL, 'flash' => $flash ?? NULL)); ?>

<section class="mb-10">
  <h2 class="mb-4 text-lg font-semibold">Layanan JDC</h2>
  <div class="grid gap-4 sm:grid-cols-3">
    <?php foreach ($services as $svc): ?>
      <a href="<?= e($svc['url']) ?>" class="card block hover:border-primary-300">
        <p class="font-display text-base font-semibold text-primary-800"><?= e($svc['name']) ?></p>
        <p class="mt-1 text-sm text-primary-500"><?= e($svc['desc']) ?></p>
        <p class="mt-3 text-sm font-semibold text-primary-700">Buka &rarr;</p>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<div class="grid gap-6 lg:grid-cols-2">
  <section class="card">
    <h2 class="mb-4 text-lg font-semibold">Profil</h2>
    <form method="post" action="<?= e($base) ?>/profile" class="space-y-4">
      <?= $csrf->field() ?>
      <div>
        <label class="label" for="name">Nama Lengkap</label>
        <input id="name" name="name" class="input" required minlength="3" maxlength="150" value="<?= e($user['name']) ?>">
      </div>
      <div>
        <label class="label" for="email">Email</label>
        <input id="email" class="input" value="<?= e($user['email']) ?>" disabled>
        <p class="mt-1 text-xs text-primary-400">Perubahan email dilayani pengurus koperasi demi keamanan akun.</p>
      </div>
      <button type="submit" class="btn-primary">Simpan Profil</button>
    </form>
  </section>

  <section class="card">
    <h2 class="mb-4 text-lg font-semibold">Ganti Kata Sandi</h2>
    <form method="post" action="<?= e($base) ?>/password" class="space-y-4">
      <?= $csrf->field() ?>
      <div>
        <label class="label" for="current_password">Kata Sandi Saat Ini</label>
        <input id="current_password" name="current_password" type="password" autocomplete="current-password" required class="input">
      </div>
      <div>
        <label class="label" for="new_password">Kata Sandi Baru</label>
        <input id="new_password" name="password" type="password" autocomplete="new-password" required minlength="8" maxlength="72" class="input">
      </div>
      <div>
        <label class="label" for="password_confirm">Ulangi Kata Sandi Baru</label>
        <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required class="input">
      </div>
      <button type="submit" class="btn-primary">Ubah Kata Sandi</button>
    </form>
  </section>
</div>

<section class="card mt-6">
  <h2 class="mb-1 text-lg font-semibold">Perangkat yang Sedang Masuk</h2>
  <p class="mb-4 text-sm text-primary-500">Keluarkan perangkat yang tidak Anda kenali. Semua layanan JDC di perangkat itu ikut keluar.</p>
  <div class="overflow-x-auto">
    <table class="w-full text-left text-sm">
      <thead class="border-b border-primary-100 text-xs uppercase text-primary-500">
        <tr><th class="py-2 pr-4">Perangkat</th><th class="py-2 pr-4">IP</th><th class="py-2 pr-4">Aktif terakhir</th><th class="py-2"></th></tr>
      </thead>
      <tbody class="divide-y divide-primary-50">
        <?php foreach ($sessions as $row): ?>
        <tr>
          <td class="max-w-xs truncate py-3 pr-4" title="<?= e($row['user_agent']) ?>">
            <?= e($row['user_agent'] !== '' ? $row['user_agent'] : 'Tidak diketahui') ?>
            <?php if ($row['sid'] === $session['sid']): ?><span class="badge ml-1 bg-primary-100 text-primary-700">Perangkat ini</span><?php endif; ?>
          </td>
          <td class="py-3 pr-4"><?= e($row['ip']) ?></td>
          <td class="whitespace-nowrap py-3 pr-4"><?= e($row['last_seen_at']) ?></td>
          <td class="py-3 text-right">
            <form method="post" action="<?= e($base) ?>/sessions/revoke">
              <?= $csrf->field() ?>
              <input type="hidden" name="sid" value="<?= e($row['sid']) ?>">
              <button type="submit" class="text-sm font-semibold text-secondary-700 hover:underline">Keluarkan</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
