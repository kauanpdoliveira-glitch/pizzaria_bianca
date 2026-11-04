<?php
/** Formulário de ação do salão/caixa (POST /admin/salao/acao). $fields: nome => rótulo (campos obrigatórios exibidos ao lado do botão). */
if (!function_exists('af')) {
    function af(string $csrf, string $back, string $action, array $hidden, string $label, string $cls = 'b sm ghost', ?string $confirm = null, array $fields = []): void
    { ?>
<form method="post" action="/admin/salao/acao" class="inline af" <?= $confirm ? 'data-confirm="' . e($confirm) . '"' : '' ?>>
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="action" value="<?= e($action) ?>">
  <?php foreach ($hidden as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>"><?php endforeach; ?>
  <?php foreach ($fields as $k => $lbl): ?><label><?= e($lbl) ?><input name="<?= e($k) ?>" required <?= str_contains($k, 'amount') || $k === 'counted' ? 'inputmode="decimal"' : '' ?>></label><?php endforeach; ?>
  <button class="<?= e($cls) ?>"><?= e($label) ?></button>
</form>
<?php }
}
