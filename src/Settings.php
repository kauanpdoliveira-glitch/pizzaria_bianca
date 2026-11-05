<?php
declare(strict_types=1);

final class Settings
{
    private static ?array $cache = null;

    /** Valores padrão. Horário e telefone/endereço vêm do PDF/Instagram; o resto é neutro e deve ser definido pela gerente. */
    public const DEFAULTS = [
        'orders_open' => '1',
        'auto_accept' => '1',          // aceita no servidor o pedido válido do site (pagamento na entrega/retirada)
        'delivery_enabled' => '1',
        'pickup_enabled' => '1',
        'estimate_delivery_min' => '60',
        'estimate_pickup_min' => '30',
        'store_phone' => '(31) 3398-5252',
        'store_address' => 'Rua Cristiano Machado, 116 - Centro - Contagem/MG',
        'store_whatsapp' => '',
        'instagram_url' => 'https://www.instagram.com/biancapizzaria/',
        'links_url' => 'https://linktr.ee/BiancaPizzaria',
        // dia da semana (0=domingo) => lista de [abre, fecha]. Fonte: bio do Instagram (confirmar).
        'schedule' => '{"0":[["18:00","23:00"]],"1":[["18:00","23:00"]],"2":[["18:00","23:00"]],"3":[["18:00","23:00"]],"4":[["18:00","23:00"]],"5":[["18:00","23:00"]],"6":[["18:00","23:00"]]}',
        // chave => [rótulo, ativo, instrução]
        'payment_methods' => '{"cash":{"label":"Dinheiro na entrega/retirada","on":1},"pix":{"label":"PIX (conferência manual pela equipe)","on":1},"debit":{"label":"Cartão de débito (maquininha)","on":1},"credit":{"label":"Cartão de crédito (maquininha)","on":1},"voucher_ticket":{"label":"Vale-refeição Ticket Restaurante","on":1},"voucher_alelo":{"label":"Vale-refeição Alelo","on":1}}',
        'pix_instructions' => '',
        'half_rule' => 'average',      // average | highest
        'max_flavors' => '2',
        'print_sectors' => '{"cozinha":{"on":1,"auto":1,"copies":1,"paper":80,"values":0},"copa":{"on":1,"auto":1,"copies":1,"paper":80,"values":0},"balcao":{"on":1,"auto":1,"copies":1,"paper":80,"values":1}}',
        'print_agent_hash' => '',
        'print_agent_seen' => '',
        'print_max_attempts' => '3',
        'service_fee_percent' => '',          // vazio = sem taxa de serviço; o proprietário define
        'allow_independent_visits' => '1',    // permite abrir um atendimento independente numa mesa já ocupada
        'table_count' => '0',
    ];

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            foreach (Db::all('SELECT skey, svalue FROM settings') as $r) self::$cache[$r['skey']] = $r['svalue'];
        }
        return self::$cache;
    }
    public static function get(string $k): string { return self::all()[$k] ?? ''; }
    public static function json(string $k): array { return json_decode(self::get($k), true) ?: []; }
    public static function set(string $k, string $v): void
    {
        if (Db::val('SELECT 1 FROM settings WHERE skey = ?', [$k])) Db::exec('UPDATE settings SET svalue = ? WHERE skey = ?', [$v, $k]);
        else Db::insert('settings', ['skey' => $k, 'svalue' => $v]);
        self::$cache = null;
    }

    /** @return array{open:bool, reason:string} */
    public static function storeStatus(?DateTimeImmutable $at = null): array
    {
        if (self::get('orders_open') !== '1') return ['open' => false, 'reason' => 'Pedidos pausados no momento.'];
        $at ??= new DateTimeImmutable('now');
        $slots = self::json('schedule')[(string)$at->format('w')] ?? [];
        $t = $at->format('H:i');
        foreach ($slots as [$from, $to]) if ($t >= $from && $t < $to) return ['open' => true, 'reason' => ''];
        return ['open' => false, 'reason' => 'Fora do horário de funcionamento.'];
    }
}
