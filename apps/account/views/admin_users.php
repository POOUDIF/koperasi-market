<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
  <div>
    <p class="text-sm font-semibold uppercase tracking-wider text-secondary-600">Admin Platform</p>
    <h1 class="mt-1 font-display text-2xl font-bold text-primary-800">Akun JDC</h1>
    <p class="text-sm text-primary-500"><?= (int) $total ?> akun</p>
  </div>
  <form method="get" action="<?= e($base) ?>/admin/users" class="flex gap-2">
    <input name="q" value="<?= e($q) ?>" class="input" placeholder="Cari nama / email">
    <button class="btn-secondary" type="submit">Cari</button>
  </form>
</div>

<?php $this->load->view('partials/alerts', array('error' => NULL, 'flash' => $flash ?? NULL)); ?>

<div class="card overflow-x-auto p-0">
  <table class="w-full text-left text-sm">
    <thead class="border-b border-primary-100 bg-cream-50 text-xs uppercase text-primary-500">
      <tr><th class="px-4 py-3">ID</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Email</th><th class="px-4 py-3">Terverifikasi</th><th class="px-4 py-3">Status</th><th class="px-4 py-3"></th></tr>
    </thead>
    <tbody class="divide-y divide-primary-50">
    <?php foreach ($items as $u): ?>
      <tr>
        <td class="px-4 py-3 text-primary-400"><?= (int) $u['id'] ?></td>
        <td class="px-4 py-3 font-medium"><?= e($u['name']) ?><?php if ((int) $u['is_platform_admin'] === 1): ?> <span class="badge bg-gold-100 text-gold-800">admin</span><?php endif; ?></td>
        <td class="px-4 py-3"><?= e($u['email']) ?></td>
        <td class="px-4 py-3"><?= $u['email_verified_at'] ? 'Ya' : 'Belum' ?></td>
        <td class="px-4 py-3">
          <span class="badge <?= $u['status'] === 'active' ? 'bg-primary-100 text-primary-700' : 'bg-secondary-100 text-secondary-800' ?>"><?= e($u['status']) ?></span>
        </td>
        <td class="px-4 py-3 text-right">
          <?php if ((int) $u['id'] !== (int) $me['id']): ?>
          <form method="post" action="<?= e($base) ?>/admin/users/<?= (int) $u['id'] ?>/status">
            <?= $csrf->field() ?>
            <input type="hidden" name="q" value="<?= e($q) ?>">
            <input type="hidden" name="status" value="<?= $u['status'] === 'active' ? 'banned' : 'active' ?>">
            <button type="submit" class="text-sm font-semibold <?= $u['status'] === 'active' ? 'text-secondary-700' : 'text-primary-700' ?> hover:underline">
              <?= $u['status'] === 'active' ? 'Blokir' : 'Pulihkan' ?>
            </button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($pages > 1): ?>
<nav class="mt-4 flex items-center justify-between text-sm">
  <span class="text-primary-500">Halaman <?= (int) $page ?> dari <?= (int) $pages ?></span>
  <span class="flex gap-2">
    <?php if ($page > 1): ?><a class="btn-secondary" href="<?= e($base) ?>/admin/users?q=<?= rawurlencode($q) ?>&page=<?= $page - 1 ?>">Sebelumnya</a><?php endif; ?>
    <?php if ($page < $pages): ?><a class="btn-secondary" href="<?= e($base) ?>/admin/users?q=<?= rawurlencode($q) ?>&page=<?= $page + 1 ?>">Berikutnya</a><?php endif; ?>
  </span>
</nav>
<?php endif; ?>
