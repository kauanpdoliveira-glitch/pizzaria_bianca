<section class="hero" aria-labelledby="h1">
  <p class="eyebrow" style="color:#fff">Contagem · Minas Gerais</p>
  <h1 id="h1">Pizzaria Bianca. <span class="brk">Tradição desde 1977.</span></h1>
  <p class="lead">Do Centro de Contagem para a sua mesa: peça pelo site e receba em casa ou retire no balcão.</p>
  <div class="cta-row"><a class="btn btn-light" href="/cardapio">Fazer pedido</a><a class="btn btn-line" href="/historia">Nossa história</a></div>
  <p class="status-pill <?= $status['open'] ? 'on' : 'off' ?>"><span class="dot"></span><?= $status['open'] ? 'Recebendo pedidos agora' : e($status['reason'] ?: 'Fechados no momento') ?></p>
  <div class="hero-pizza"><img src="/assets/img/hero-pizza.jpg" srcset="/assets/img/hero-pizza-sm.jpg 520w, /assets/img/hero-pizza-640.jpg 640w, /assets/img/hero-pizza.jpg 900w" sizes="(max-width:640px) 86vw, 560px" width="900" height="900" fetchpriority="high" alt="Pizza com calabresa, presunto e pimentão na frigideira de ferro"></div>
</section>
<div class="hero-spacer"></div>

<section class="wrap sec" aria-labelledby="fav">
  <div class="sec-title"><h2 id="fav">Escolha sua próxima favorita</h2></div>
  <div class="cats">
    <?php // grade de 4 colunas (desktop): a 1ª categoria ocupa 2x2; a última completa a linha para não sobrar buraco
    $nT = count($tiles); $cells = ($nT ? 3 : 0) + $nT; $tailSpan = 1 + ((4 - $cells % 4) % 4); $iT = 0; ?>
    <?php foreach ($tiles as $t): $iT++; $tail = $iT === $nT && $nT > 1 && $tailSpan > 1; ?>
      <a class="tile <?= $t['big'] ? 'big' : '' ?> <?= $t['image'] ? '' : 'noimg' ?> <?= $tail ? 'tail' : '' ?>" <?= $tail ? 'style="--span:' . $tailSpan . '"' : '' ?> href="/cardapio#cat-<?= (int)$t['id'] ?>">
        <?php if ($t['image']): ?><img src="<?= e($t['image']) ?>" alt="" loading="lazy" width="900" height="600"><?php else: $k = preg_match('/bebida|suco|cerveja|vinho/i', $t['name']) ? '<path d="M14 8h20l-3 32H17z"/><path d="M15.5 18h17"/>' : (preg_match('/sobremesa|doce/i', $t['name']) ? '<path d="M6 36V22l36-8v22z"/><path d="M6 28l36-8"/>' : '<circle cx="24" cy="26" r="14"/><circle cx="24" cy="26" r="8"/><path d="M5 8v9a3 3 0 0 0 3 3v14M43 8c-4 3-4 9 0 12v14"/>'); ?><svg class="tile-ic" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><?= $k ?></svg><?php endif; ?><span><?= e($t['name']) ?></span></a>
    <?php endforeach; ?>
  </div>
</section>

<section class="wrap" aria-label="A Bianca em números">
  <div class="facts">
    <div><b>1977</b><span>ano em que a Bianca nasceu</span></div>
    <div><b>1995</b><span>sede atual e início da tele-entrega</span></div>
    <div><b>48 h</b><span>de preparo do molho madeira da casa</span></div>
    <div><b>+100 mil</b><span>pessoas no Instagram</span></div>
  </div>
</section>

<section class="cta-band" style="margin-top:var(--sp-section)">
  <div class="copy"><h2>Peça sua pizza <span class="brk">agora mesmo</span></h2><p>Escolha tamanho, sabores e borda, e acompanhe o pedido até a sua casa.</p><a class="btn btn-light" href="/cardapio">Fazer pedido</a></div>
  <img src="/assets/img/pizza-calabresa.jpg" alt="Pizza de calabresa na frigideira de ferro" loading="lazy" width="900" height="600">
</section>

<section class="wrap sec" aria-labelledby="hist">
  <div class="split">
    <div class="prose">
      <p class="eyebrow">Nossa história à mesa</p>
      <h2 id="hist">De dois sócios na Praça Tiradentes a quase meio século de Contagem</h2>
      <p class="lede">Em 1977, Carlos Camargos (Bonitão) e Nadiorlando Veronez abriram a Bianca na Praça Tiradentes, ao lado do atual Mercadão da Carne. O nome veio de uma conversa entre amigos: era o que um deles pretendia dar à filha que ia nascer.</p>
      <p>Em 1995 a casa mudou para o endereço onde está até hoje e começou a fazer tele-entrega — numa época sem internet, com rotas traçadas em mapas.</p>
      <p><a class="btn btn-ghost" href="/historia">Ler a história completa</a></p>
    </div>
    <figure class="photo"><img src="/assets/img/kombi.jpg" alt="Kombi da Bianca estacionada em uma praça, com o selo 100K na lateral" loading="lazy" width="900" height="1200"><figcaption>A Kombi da Bianca, com o selo 100K.</figcaption></figure>
  </div>
</section>
