<h1>Estação: <?= e($station['name']) ?></h1>
<section class="panel" id="qzst" data-station="<?= (int)$station['id'] ?>" data-csrf="<?= e($csrf) ?>" data-active="<?= (int)$station['active'] ?>">
<p>Mantenha esta página <b>aberta</b> no computador da loja (com o QZ Tray rodando). Ela busca trabalhos do servidor e envia à impressora mapeada. <a href="/admin/estacoes">Gerenciar estações</a></p>
<ul class="kv">
<li>Servidor: <b id="st-srv">aguardando…</b></li>
<li>QZ Tray: <b id="st-qz">desconectado</b> <span id="st-qzver" class="muted"></span></li>
<li>Assinatura: <b><?= $signer['configured'] ? 'ativa (impressão silenciosa possível se o QZ confiar no certificado)' : 'não configurada (o QZ pedirá confirmação manual)' ?></b></li>
<li>Impressoras vistas: <span id="st-printers" class="muted">—</span></li>
<li>Trabalho atual: <b id="st-job">nenhum</b></li>
</ul>
<?php if (Printing::testEnvBlocked()): ?><p class="errmsg">Ambiente de testes: a impressão física está DESLIGADA (PRINT_ENABLED não é 1). A estação conecta, mas não recebe trabalhos.</p><?php endif; ?>
<?php if (!$station['active']): ?><p class="errmsg">Esta estação está desativada; reative em “Gerenciar estações”.</p><?php endif; ?>
<button class="b ghost" id="st-refresh" type="button">Atualizar impressoras</button>
<h2>Registro</h2><ol id="st-log" class="muted" aria-live="polite"></ol>
<p class="note">Se o QZ não conectar: confirme que o QZ Tray está aberto (ícone na bandeja) e que este navegador pode acessar <code>localhost</code>. Se pedir permissão, a confirmação é do QZ Tray, não deste sistema.</p>
</section>
<script src="<?= View::asset('assets/vendor/qz-tray.js') ?>"></script>
<script src="<?= View::asset('assets/qz-station.js') ?>" defer></script>
