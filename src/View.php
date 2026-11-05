<?php
declare(strict_types=1);

final class View
{
    /** Renderiza views/{name}.php dentro do layout. $data vira variáveis locais. */
    public static function render(string $name, array $data = [], string $layout = 'layout'): void
    {
        $content = self::capture($name, $data);
        extract($data + ['title' => 'Pizzaria Bianca', 'description' => '', 'page' => $name], EXTR_SKIP);
        if (env('APP_ENV') !== 'staging') { require APP_ROOT . "/views/$layout.php"; return; }
        ob_start(); require APP_ROOT . "/views/$layout.php"; echo self::stagingMark((string)ob_get_clean());
    }

    /** Ambiente de testes: faixa visível em toda página + noindex (nada de dados reais, pagamentos ou impressão automática). */
    public static function stagingMark(string $html): string
    {
        $banner = '<div role="status" style="position:relative;z-index:99999;background:#7a1f00;color:#fff;padding:6px max(16px,4vw);font:600 13px/1.3 system-ui,sans-serif;text-align:center">HOMOLOGAÇÃO — testes da Bianca: não é a loja oficial; pedidos aqui são de teste</div>';
        $html = preg_replace('/(<body\b[^>]*>)/i', '$1' . $banner, $html, 1) ?? $html;
        return preg_replace('/<\/head>/i', '<meta name="robots" content="noindex,nofollow"></head>', $html, 1) ?? $html;
    }

    public static function capture(string $name, array $data = []): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require APP_ROOT . "/views/$name.php";
        return ob_get_clean();
    }

    /** Logo da marca (branca, transparente). $ctx define o tamanho em brand.css; largura/altura reservadas evitam deslocamento de layout. */
    public static function brand(string $ctx = 'header', bool $lazy = false): string
    {
        $sizes = ['header' => '132px', 'footer' => '220px', 'side' => '148px', 'garcom' => '124px', 'login' => '220px'][$ctx] ?? '132px';
        $b = '/assets/brand/logo-bianca-';
        return '<img class="brand-img brand-img--' . $ctx . '" src="' . $b . '600.png" srcset="' . $b . '360.png 360w, ' . $b . '600.png 600w, ' . $b . '1200.png 1200w" sizes="' . $sizes
            . '" width="600" height="279" alt="Bianca Pizzaria — desde 1977" decoding="async"' . ($lazy ? ' loading="lazy"' : '') . '>';
    }

    /** Usa os arquivos minificados (public/assets/dist, gerados por bin/build-assets.php) em produção; ASSETS_MIN=1/0 força. */
    public static function minified(): bool
    {
        $v = env('ASSETS_MIN');
        return $v === '1' || ($v !== '0' && env('APP_ENV') === 'production');
    }

    public static function asset(string $p): string
    {
        if (self::minified() && preg_match('#^assets/([a-z]+)\.(css|js)$#', $p, $m) && is_file(APP_ROOT . "/public/assets/dist/{$m[1]}.min.{$m[2]}")) $p = "assets/dist/{$m[1]}.min.{$m[2]}";
        $f = APP_ROOT . '/public/' . $p;
        return '/' . $p . (is_file($f) ? '?v=' . filemtime($f) : '');
    }

    /** Páginas que precisam do CSS/JS da loja (cardápio, checkout, acompanhamento, erro). A home/história carregam só o essencial. */
    public static function isShop(string $page): bool { return in_array($page, ['menu', 'checkout', 'track', 'error'], true); }

    /** Em produção o CSS da loja é um arquivo à parte; em desenvolvimento ele já está dentro de site.css. */
    public static function shopCss(string $page): string
    {
        return self::minified() && self::isShop($page) && is_file(APP_ROOT . '/public/assets/dist/shop.min.css') ? '<link rel="stylesheet" href="' . self::asset('assets/shop.css') . '">' : '';
    }
}
