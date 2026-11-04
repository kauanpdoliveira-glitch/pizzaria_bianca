<div class="noprint toolbar-c"><button class="b" id="do-print" type="button">Imprimir (abre a janela do navegador)</button>
<span class="muted">A impressão pelo navegador exige confirmação na janela de impressão — não é automática. Papel: <?= (int)$paper ?> mm.</span></div>
<pre class="ticket w<?= (int)$paper ?>"><?php foreach ($lines as $l): ?><span class="<?= !empty($l['b']) ? 'b' : '' ?> <?= !empty($l['big']) ? 'big' : '' ?> <?= !empty($l['c']) ? 'c' : '' ?>"><?= e($l['t']) ?></span>
<?php endforeach; ?></pre>
<style>@page{size:<?= (int)$paper ?>mm auto;margin:2mm}</style>
