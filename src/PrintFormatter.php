<?php
declare(strict_types=1);

/**
 * Formatação de comandas para impressoras térmicas ESC/POS (80/58 mm), por perfil de impressora.
 * - Todo texto vindo de pessoas (nome, observações, endereço) passa por clean(): nenhum byte de controle chega à impressora,
 *   então ninguém consegue injetar comandos ESC/POS. Só o formatador (confiável) emite comandos.
 * - Charsets: ascii (padrão seguro: sem acentos), cp860 (português), cp850, cp1252. O modelo real precisa ser validado em papel.
 */
final class PrintFormatter
{
    /** código da tabela de caracteres (ESC t n) por charset — varia por fabricante; confirmar no manual do modelo. */
    public const ESC_T = ['ascii' => 0, 'cp860' => 3, 'cp850' => 2, 'cp1252' => 16];
    /** CP860 (português) — bytes 0x80–0xAF. */
    private const CP860 = ['Ç' => 0x80, 'ü' => 0x81, 'é' => 0x82, 'â' => 0x83, 'ã' => 0x84, 'à' => 0x85, 'Á' => 0x86, 'ç' => 0x87, 'ê' => 0x88, 'Ê' => 0x89, 'è' => 0x8A, 'Í' => 0x8B,
        'Ô' => 0x8C, 'ì' => 0x8D, 'Ã' => 0x8E, 'Â' => 0x8F, 'É' => 0x90, 'À' => 0x91, 'È' => 0x92, 'ô' => 0x93, 'õ' => 0x94, 'ò' => 0x95, 'Ú' => 0x96, 'ù' => 0x97, 'Ì' => 0x98,
        'Õ' => 0x99, 'Ü' => 0x9A, 'Ù' => 0x9D, 'Ó' => 0x9F, 'á' => 0xA0, 'í' => 0xA1, 'ó' => 0xA2, 'ú' => 0xA3, 'ñ' => 0xA4, 'Ñ' => 0xA5, 'ª' => 0xA6, 'º' => 0xA7, 'Ò' => 0xA9, '½' => 0xAB, '¼' => 0xAC];
    private const ASCII_MAP = ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ç' => 'c', 'ñ' => 'n', 'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'É' => 'E', 'Ê' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O',
        'Ú' => 'U', 'Ç' => 'C', 'Ñ' => 'N', '½' => '1/2', '¼' => '1/4', 'º' => 'o', 'ª' => 'a', '–' => '-', '—' => '-', '‘' => "'", '’' => "'", '“' => '"', '”' => '"', '…' => '...', '•' => '*', '×' => 'x'];

    /** Remove caracteres de controle (C0, DEL, C1, separadores de linha Unicode) e normaliza espaços. Nunca deixa passar ESC (0x1B) ou GS (0x1D). */
    public static function clean(string $s): string
    {
        if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');   // UTF-8 inválido vira "?"
        $s = (string)preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]+/u', ' ', $s);
        return trim((string)preg_replace('/ {2,}/', ' ', $s));
    }

    public static function ascii(string $s): string
    {
        $s = strtr($s, self::ASCII_MAP);
        return (string)preg_replace('/[^\x20-\x7E]/u', '?', $s);
    }

    /** Converte UTF-8 para os bytes do charset. Caracteres sem equivalente viram "?". */
    public static function encode(string $s, string $charset): string
    {
        if ($charset === 'cp860') {
            $o = '';
            foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) $o .= strlen($ch) === 1 && ord($ch) >= 0x20 && ord($ch) < 0x7F ? $ch : (isset(self::CP860[$ch]) ? chr(self::CP860[$ch]) : (isset(self::ASCII_MAP[$ch]) ? self::ASCII_MAP[$ch] : '?'));
            return $o;
        }
        if ($charset === 'cp850') return mb_convert_encoding(strtr($s, ['½' => '1/2', '–' => '-', '—' => '-', '’' => "'", '“' => '"', '”' => '"']), 'CP850', 'UTF-8');
        if ($charset === 'cp1252') return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        return self::ascii($s);
    }

    /** Quebra de linha ciente de UTF-8 (largura em caracteres). */
    public static function wrap(string $t, int $w): array
    {
        $out = []; $line = '';
        foreach (preg_split('/\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [''] as $word) {
            while (mb_strlen($word) > $w) { if ($line !== '') { $out[] = $line; $line = ''; } $out[] = mb_substr($word, 0, $w); $word = mb_substr($word, $w); }
            if ($line === '') $line = $word; elseif (mb_strlen($line) + 1 + mb_strlen($word) <= $w) $line .= ' ' . $word; else { $out[] = $line; $line = $word; }
        }
        $out[] = $line;
        return $out;
    }

    public static function pad(string $s, int $w, bool $left = true): string
    {
        $n = $w - mb_strlen($s);
        return $n <= 0 ? $s : ($left ? str_repeat(' ', $n) . $s : $s . str_repeat(' ', $n));
    }

    /**
     * @param list<array{t:string,b?:bool,big?:bool,c?:bool}> $lines
     * @param array{charset?:string,cut?:bool,feed?:int} $profile
     */
    public static function escpos(array $lines, array $profile = []): string
    {
        $cs = isset(self::ESC_T[$profile['charset'] ?? 'ascii']) ? ($profile['charset'] ?? 'ascii') : 'ascii';
        $pad = str_repeat(' ', max(0, min(20, (int)($profile['margin'] ?? 0))));
        $one = "\x1B@\x1Bt" . chr(self::ESC_T[$cs]);
        foreach ($lines as $l) {
            $one .= "\x1Ba" . (!empty($l['c']) ? "\x01" : "\x00") . "\x1BE" . (!empty($l['b']) ? "\x01" : "\x00") . "\x1D!" . (!empty($l['big']) ? "\x11" : "\x00");
            $one .= self::encode($pad . self::clean($l['t']), $cs) . "\n";   // defesa em profundidade: limpa de novo na saída
        }
        $one .= "\x1D!\x00\x1BE\x00\x1Ba\x00" . str_repeat("\n", max(0, (int)($profile['feed'] ?? 3)));
        if (($profile['cut'] ?? true)) $one .= "\x1DV\x42\x03";   // corte parcial (só se o perfil da impressora diz que há guilhotina)
        return str_repeat($one, max(1, min(5, (int)($profile['copies'] ?? 1))));   // vias: cada uma com init e corte próprios
    }

    /** Página HTML para impressoras comuns (sem ESC/POS). Texto sempre escapado; largura em mm (58/80) ou página A4 (210). */
    public static function html(array $lines, array $profile = []): string
    {
        $paper = (int)($profile['paper'] ?? 80); $w = $paper === 210 ? '190mm' : ($paper === 58 ? '48mm' : '72mm');
        $b = '';
        foreach ($lines as $l) {
            $t = htmlspecialchars(self::clean($l['t']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $b .= '<div style="' . (!empty($l['c']) ? 'text-align:center;' : '') . (!empty($l['b']) ? 'font-weight:bold;' : '') . (!empty($l['big']) ? 'font-size:15pt;' : '') . '">' . ($t === '' ? '&nbsp;' : $t) . '</div>';
        }
        return '<!doctype html><html><head><meta charset="utf-8"><style>body{margin:0;width:' . $w . ';font:10pt/1.25 "Courier New",monospace;color:#000}div{white-space:pre-wrap;word-break:break-word}</style></head><body>' . $b . '</body></html>';
    }
}
