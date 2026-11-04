<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><?php require APP_ROOT . '/views/_icons_head.php'; ?>
<title><?= e($title) ?></title><link rel="stylesheet" href="<?= View::asset('assets/brand.css') ?>"><link rel="stylesheet" href="<?= View::asset('assets/admin.css') ?>"></head>
<body class="bare <?= e($page) ?>"><main><?= $content ?></main><?php if ($page === 'admin/comanda'): ?><script src="<?= View::asset('assets/comanda.js') ?>" defer></script><?php endif; ?></body></html>
