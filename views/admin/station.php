<h1>Estação: <?= e($station['name']) ?></h1>
<section class="panel" id="qzst" data-station="<?= (int)$station['id'] ?>" data-csrf="<?= e($csrf) ?>" data-active="<?= (int)$station['active'] ?>" data-paused="<?= (int)$station['paused'] ?>">
<p>Mantenha esta página <b>aberta</b> neste computador, com o QZ Tray rodando. Ao <b>Iniciar processamento</b> (você confirma que computador e impressoras estão prontos), ela busca pedidos do servidor e envia à impressora configurada. Com a aba fechada nada imprime. <a href="/admin/config/impressao">Configurações → Impressão</a></p>
<ul class="kv">
<li>Servidor: <b id="st-srv">aguardando…</b> · Abas: <span id="st-tabs">—</span></li>
<li>QZ Tray: <b id="st-qz">desconectado</b> <span id="st-qzver" class="muted"></span></li>
<li>Processamento: <b id="st-armed">parado</b> · Administração: <b id="st-paused"><?= $station['paused'] ? 'PAUSADA pelo administrador' : 'ativa' ?></b></li>
<li>Assinatura: <b><?= $signer['configured'] ? 'ativa (silencioso só se o QZ confiar no certificado)' : 'sem certificado próprio: o QZ vai pedir confirmação manual — isto NÃO é impressão silenciosa' ?></b></li>
<li>Filas vistas pelo QZ: <span id="st-printers" class="muted">—</span></li>
<li>Trabalho atual: <b id="st-job">nenhum</b></li>
</ul>
<?php if (Printing::testEnvBlocked()): ?><p class="errmsg">Ambiente de testes: a impressão física está DESLIGADA (PRINT_ENABLED não é 1). A estação conecta, mas não recebe trabalhos.</p><?php endif; ?>
<?php if (!$station['active']): ?><p class="errmsg">Esta estação está desativada; reative em “Configurações → Impressão”.</p><?php endif; ?>
<p><button class="b ghost" id="st-connect" type="button">Conectar ao QZ</button> <button class="b ghost" id="st-refresh" type="button">Atualizar impressoras</button> <button class="b" id="st-arm" type="button">Iniciar processamento</button><button class="b ghost" id="st-disarm" type="button" hidden>Parar processamento</button></p>
<h2>Registro</h2><ol id="st-log" class="muted" aria-live="polite"></ol>
<p class="note"><span>Se o QZ pedir permissão (janela do próprio QZ Tray), a confirmação é esperada neste modo de teste. Se não conectar: confirme o ícone do QZ na bandeja e o endereço <code>localhost</code>. Para impressão de teste use o botão em Configurações → Impressão (autoriza apenas aquele teste).</span></p>
</section>
<script src="<?= View::asset('assets/vendor/qz-tray.js') ?>"></script>
<script src="<?= View::asset('assets/qz-station.js') ?>" defer></script>
