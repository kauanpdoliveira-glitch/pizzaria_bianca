<div class="bar"><h1>Usuários e permissões</h1><a class="b ghost" href="/admin/acessos">Auditoria de acessos</a></div>
<p class="note">Somente o proprietário vê esta tela. Gerentes e garçons não têm acesso a ela nem às suas rotas. Alterar perfil, permissões, senha ou ativação encerra as sessões abertas daquela pessoa.</p>
<?php
$perm_groups = ['manager' => Auth::GRANTABLE['manager'], 'waiter' => Auth::GRANTABLE['waiter']];
$permBoxes = function (string $role, array $on) { foreach (Auth::GRANTABLE[$role] as $p): ?>
  <label class="ck"><input type="checkbox" name="perms[<?= e($p) ?>]" value="1" <?= in_array($p, $on, true) ? 'checked' : '' ?>> <?= e(Auth::PERM_LABELS[$p]) ?></label>
<?php endforeach; };
?>
<?php foreach ($users as $u): ?>
<section class="panel">
  <h2><?= e($u['name']) ?> <span class="pill"><?= e(Auth::ROLES[$u['role']] ?? $u['role']) ?></span> <span class="pill <?= $u['active'] ? '' : 'off' ?>"><?= $u['active'] ? 'ativo' : 'desativado' ?></span></h2>
  <p class="muted small"><?= e($u['email']) ?> · último acesso: <?= $u['last_login_at'] ? e(date('d/m/Y H:i', strtotime($u['last_login_at']))) : 'nunca' ?></p>
  <?php if ($u['role'] === 'owner'): ?>
    <p class="muted small">Proprietário: acesso total. Esta conta não é editada nem desativada por aqui.</p>
  <?php else: ?>
  <form method="post" class="stack">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="update">
    <div class="inline"><label>Nome<input name="name" value="<?= e($u['name']) ?>" required></label>
      <label>Perfil<select name="role"><option value="manager" <?= $u['role'] === 'manager' ? 'selected' : '' ?>>Gerente</option><option value="waiter" <?= $u['role'] === 'waiter' ? 'selected' : '' ?>>Garçom</option></select></label></div>
    <fieldset class="perms"><legend>Permissões (valem para o perfil escolhido; ao trocar de perfil, marque de novo)</legend><?php $permBoxes($u['role'], $u['effective']); ?></fieldset>
    <button class="b sm">Salvar alterações</button>
  </form>
  <div class="inline">
    <form method="post"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="toggle"><button class="b sm ghost"><?= $u['active'] ? 'Desativar' : 'Reativar' ?></button></form>
    <form method="post" data-confirm="Encerrar todas as sessões abertas desta pessoa?"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="revoke"><button class="b sm ghost">Encerrar sessões</button></form>
    <form method="post" class="inline"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="password"><input type="password" name="password" placeholder="Nova senha" autocomplete="new-password" aria-label="Nova senha de <?= e($u['name']) ?>" required><button class="b sm ghost">Trocar senha</button></form>
  </div>
  <?php endif; ?>
</section>
<?php endforeach; ?>
<section class="panel"><h2>Novo funcionário</h2>
  <form method="post" class="stack"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="create">
    <div class="inline"><label>Nome<input name="name" required></label><label>E-mail<input type="email" name="email" required></label><label>Senha inicial (mín. 10, letras e números)<input type="password" name="password" autocomplete="new-password" required></label>
      <label>Perfil<select name="role" id="newrole"><option value="waiter">Garçom</option><option value="manager">Gerente</option></select></label></div>
    <fieldset class="perms"><legend>Permissões iniciais</legend>
      <p class="muted small">Garçom: <?= e(implode(' · ', array_map(fn($p) => Auth::PERM_LABELS[$p], Auth::ROLE_DEFAULTS['waiter']))) ?>. Gerente: todas as marcadas abaixo. Ajuste depois, se precisar.</p>
      <?php foreach (Auth::GRANTABLE['manager'] as $p): ?><label class="ck"><input type="checkbox" name="perms[<?= e($p) ?>]" value="1" <?= $p === 'print_config' ? '' : 'checked' ?>> <?= e(Auth::PERM_LABELS[$p]) ?></label><?php endforeach; ?>
    </fieldset>
    <button class="b">Cadastrar</button>
    <p class="muted small">Não existe cadastro público nem criação de outro proprietário por esta tela. Para garçom, só valem "Salão" e "Informar recebimento"; o servidor descarta o resto.</p>
  </form>
</section>
