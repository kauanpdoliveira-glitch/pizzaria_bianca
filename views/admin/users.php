<?php $ic = fn(string $n) => '<svg class="ic" aria-hidden="true" focusable="false"><use href="#i-' . $n . '"/></svg>';
$permBoxes = function (string $role, array $on) { foreach (Auth::GRANTABLE[$role] as $p): ?>
  <label class="ck"><input type="checkbox" name="perms[<?= e($p) ?>]" value="1" <?= in_array($p, $on, true) ? 'checked' : '' ?>> <?= e(Auth::PERM_LABELS[$p]) ?></label>
<?php endforeach; }; ?>
<div class="card-h" style="margin-bottom:10px"><span class="pill-st mute"><?= $ic('users') ?> Somente proprietário</span><a class="b ghost sm" style="margin-left:auto" href="/admin/acessos">Auditoria de acessos</a></div>
<p class="info-box"><?= $ic('alert') ?><span>Somente o proprietário vê esta tela e suas rotas. Cada pessoa define a <b>própria senha</b> por um convite enviado por e-mail (link de uso único, válido por 48 h). Alterar perfil, permissões ou ativação encerra as sessões abertas daquela pessoa.</span></p>
<div class="split">
<div>
<section class="card"><div class="card-h"><h2>Usuários da loja (<?= count($users) ?>)</h2></div>
<?php foreach ($users as $u): $pending = Invite::pending($u); $stL = $pending ? ($u['invite_open'] ? ['Convite pendente', 'warn'] : ['Convite expirado/revogado', 'bad']) : ($u['active'] ? ['Ativo', 'ok'] : ['Desativado', 'mute']); ?>
<details class="li" style="display:block;padding:10px 0"><summary style="display:flex;align-items:center;gap:12px;cursor:pointer;list-style:none;flex-wrap:wrap"><span class="avatar-sm" style="width:40px;height:40px"><?= $ic('user') ?></span><span style="flex:1;min-width:160px"><b><?= e($u['name']) ?></b><small style="display:block;color:var(--muted)"><?= e($u['email']) ?> · último acesso: <?= $u['last_login_at'] ? e(date('d/m/Y H:i', strtotime($u['last_login_at']))) : 'nunca' ?></small></span><span class="pill-st mute"><?= e(Auth::ROLES[$u['role']] ?? $u['role']) ?></span><?= $u['badge'] ? '<span class="pill-st mute">' . e($u['badge']) . '</span>' : '' ?><span class="pill-st <?= $stL[1] ?>"><?= $stL[0] ?></span><span class="b ghost sm">Gerenciar</span></summary>
  <div style="margin-top:10px">
  <?php if ($u['role'] === 'owner'): ?>
    <p class="muted small">Proprietário: acesso total. Esta conta não é editada nem desativada por aqui.<?= $pending ? ' Aguardando o primeiro acesso por convite.' : '' ?></p>
  <?php else: ?>
  <?php if ($pending): ?>
  <div class="inline">
    <form method="post"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="resend"><button class="b sm">Reenviar convite</button></form>
    <?php if ($u['invite_open']): ?><form method="post" data-confirm="Revogar o convite? O link atual deixa de funcionar."><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="revokeinvite"><button class="b sm ghost">Revogar convite</button></form><?php endif; ?>
  </div>
  <?php else: ?>
  <form method="post" class="stack">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="update">
    <div class="inline"><label>Nome<input name="name" value="<?= e($u['name']) ?>" required></label>
      <label>Perfil<select name="role"><?php foreach (['manager', 'waiter', 'station'] as $r): ?><option value="<?= e($r) ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= e(Auth::ROLES[$r]) ?></option><?php endforeach; ?></select></label></div>
    <fieldset class="perms"><legend>Permissões (valem para o perfil escolhido; ao trocar de perfil, marque de novo)</legend><?php $permBoxes($u['role'], $u['effective']); ?></fieldset>
    <button class="b sm">Salvar alterações</button>
  </form>
  <div class="inline">
    <form method="post"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="toggle"><button class="b sm ghost"><?= $u['active'] ? 'Desativar' : 'Reativar' ?></button></form>
    <form method="post" data-confirm="Encerrar todas as sessões abertas desta pessoa?"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="revoke"><button class="b sm ghost">Encerrar sessões</button></form>
  </div>
  <?php endif; endif; ?>
  </div></details>
<?php endforeach; ?></section>
<section class="card"><div class="card-h"><h2>Resumo de permissões</h2><p>Visão geral do que cada perfil pode acessar.</p></div>
  <ul class="kv-list"><li><span><b style="color:var(--ink)"><?= e(Auth::ROLES['owner'] ?? 'Proprietário') ?></b></span><span>Acesso total ao sistema, inclusive cadastro de funcionários e permissões.</span></li>
  <li><span><b style="color:var(--ink)"><?= e(Auth::ROLES['manager'] ?? 'Gerente') ?></b></span><span><?= e(implode(' · ', array_map(fn($p) => Auth::PERM_LABELS[$p], Auth::GRANTABLE['manager']))) ?> (conforme marcado em cada pessoa). Nunca altera usuários e permissões.</span></li>
  <li><span><b style="color:var(--ink)"><?= e(Auth::ROLES['waiter'] ?? 'Garçom') ?></b></span><span><?= e(implode(' · ', array_map(fn($p) => Auth::PERM_LABELS[$p], Auth::ROLE_DEFAULTS['waiter']))) ?></span></li>
  <li><span><b style="color:var(--ink)"><?= e(Auth::ROLES['station'] ?? 'Estação') ?></b></span><span><?= e(implode(' · ', array_map(fn($p) => Auth::PERM_LABELS[$p], Auth::ROLE_DEFAULTS['station']))) ?></span></li></ul></section>
</div>
<aside class="card" aria-label="Convidar usuário"><div class="card-h"><h2>Convidar usuário</h2><p>Envie um convite para um novo membro da equipe.</p></div>
  <form method="post" class="stack"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="invite">
    <label>Nome<input name="name" required></label><label>E-mail<input type="email" name="email" required placeholder="email do funcionário"></label>
    <label>Perfil<select name="role" id="newrole"><option value="waiter">Garçom</option><option value="manager">Gerente</option><option value="station">Estação de impressão (conta do computador com a impressora)</option></select></label>
    <fieldset class="perms"><legend>Permissões iniciais (gerente)</legend>
      <p class="muted small">Garçom: <?= e(implode(' · ', array_map(fn($p) => Auth::PERM_LABELS[$p], Auth::ROLE_DEFAULTS['waiter']))) ?>. Estação: <?= e(implode(' · ', array_map(fn($p) => Auth::PERM_LABELS[$p], Auth::ROLE_DEFAULTS['station']))) ?>. Gerente: as marcadas abaixo; o servidor descarta o que o perfil não permite.</p>
      <?php foreach (Auth::GRANTABLE['manager'] as $p): ?><label class="ck"><input type="checkbox" name="perms[<?= e($p) ?>]" value="1" <?= $p === 'print_config' ? '' : 'checked' ?>> <?= e(Auth::PERM_LABELS[$p]) ?></label><?php endforeach; ?>
    </fieldset>
    <button class="b block"><?= $ic('plus') ?> Enviar convite</button>
    <p class="muted small">A pessoa recebe um e-mail com o link para definir a própria senha. Sem cadastro público; outro proprietário só é criado por provisionamento no servidor. A conta <b>Estação de impressão</b> tem permissão mínima (só consumir a fila) e pode ser desativada a qualquer momento.</p>
  </form></aside>
</div>
