<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php if ( ! empty($error)): ?>
  <div role="alert" class="mb-5 flex items-start gap-2.5 rounded-lg border border-secondary-200 bg-secondary-50 px-4 py-3 text-sm text-secondary-800">
    <span aria-hidden="true">⚠️</span><span><?= e($error) ?></span>
  </div>
<?php endif; ?>
<?php if ( ! empty($flash)): ?>
  <div role="status" class="mb-5 rounded-lg border px-4 py-3 text-sm <?= $flash[0] === 'success' ? 'border-primary-200 bg-primary-50 text-primary-800' : 'border-gold-200 bg-gold-50 text-gold-800' ?>">
    <?= e($flash[1]) ?>
  </div>
<?php endif; ?>
