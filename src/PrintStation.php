<?php
declare(strict_types=1);

/**
 * Estações de impressão (navegador autorizado da loja + QZ Tray local) e as impressoras de cada estação.
 * - A estação é um navegador logado na loja (perfil com "print") com o QZ Tray no MESMO computador.
 * - Clientes e garçons NUNCA imprimem direto: pedidos entram na fila do servidor; só a estação ARMADA reserva e envia.
 * - Cada trabalho é reservado por UMA estação (UPDATE atômico) com validade curta; só depois de "sending" a assinatura do envio é liberada.
 * - Configurar estações e impressoras exige "print_config" (proprietário, ou perfil que ele autorizou explicitamente).
 * - A impressora é identificada por (estação, destino): o mesmo nome de fila em computadores diferentes não é o mesmo equipamento.
 */
final class PrintStation
{
    public const ONLINE_SECONDS = 45;
    public const CHARSETS = ['ascii', 'cp860', 'cp850', 'cp1252'];
    public const MODES = ['escpos' => 'Térmica ESC/POS (comandos brutos)', 'html' => 'Página HTML (impressora comum/Epson doméstica)'];
    public const PAPERS = [58 => '58 mm', 80 => '80 mm', 210 => 'A4 (somente modo HTML)'];
    public const STALE_JOB_MINUTES = 10;

    private static function cleanCode(string $s): string { return trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower(strtr($s, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']))), '-'); }

    public static function create(string $name, array $u, string $code = ''): int
    {
        $name = trim(PrintFormatter::clean($name));
        if ($name === '' || mb_strlen($name) > 80) throw new HttpError(422, 'Informe um nome de até 80 caracteres.');
        $code = self::cleanCode($code !== '' ? $code : $name);
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $code)) throw new HttpError(422, 'Identificador inválido: use 2 a 40 letras minúsculas, números ou hífen.');
        if (Db::val('SELECT 1 FROM print_stations WHERE code = ?', [$code])) throw new HttpError(422, 'Já existe uma estação com o identificador "' . $code . '".');
        $id = Db::insert('print_stations', ['name' => $name, 'code' => $code, 'active' => 1, 'created_by' => $u['name'], 'created_at' => now()]);
        Auth::audit('print.station.create', "estação #$id $name ($code)");
        return $id;
    }

    public static function update(int $id, string $name, string $code): void
    {
        if (!self::get($id)) throw new HttpError(404, 'Estação não encontrada.');
        $name = trim(PrintFormatter::clean($name)); $code = self::cleanCode($code);
        if ($name === '' || mb_strlen($name) > 80 || !preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $code)) throw new HttpError(422, 'Nome ou identificador inválido.');
        if (Db::val('SELECT 1 FROM print_stations WHERE code = ? AND id <> ?', [$code, $id])) throw new HttpError(422, 'Identificador já usado por outra estação.');
        Db::update('print_stations', $id, ['name' => $name, 'code' => $code]);
        Auth::audit('print.station.rename', "estação #$id $name ($code)");
    }

    public static function get(int $id): ?array { return Db::one('SELECT * FROM print_stations WHERE id = ?', [$id]); }

    public static function online(array $s): bool { return $s['last_seen'] && time() - strtotime($s['last_seen']) <= self::ONLINE_SECONDS; }
    public static function qzOnline(array $s): bool { return self::online($s) && (int)$s['qz_connected'] === 1; }

    /** Abas ativas desta estação nos últimos 60 s (a reserva atômica impede duplicar mesmo com 2 abas). */
    public static function tabs(array $s): int
    {
        $n = 0; foreach ((array)json_decode((string)$s['instances_json'], true) as $ts) if (time() - (int)$ts <= 60) $n++;
        return $n;
    }

    /** @return list<array> estações com impressoras e estado */
    public static function all(): array
    {
        $out = [];
        foreach (Db::all('SELECT * FROM print_stations ORDER BY id') as $s) {
            $s['printers'] = json_decode((string)$s['printers_json'], true) ?: [];
            $s['map'] = self::map((int)$s['id']);
            $s['online'] = self::online($s); $s['qz_online'] = self::qzOnline($s); $s['tabs'] = self::tabs($s);
            $s['queue'] = (int)Db::val("SELECT COUNT(*) FROM print_jobs WHERE station_id = ? AND status IN ('reserved','sending')", [$s['id']]);
            $out[] = $s;
        }
        return $out;
    }

    /** @return array<string,array> impressoras da estação por destino */
    public static function map(int $id): array
    {
        $m = [];
        foreach (Db::all('SELECT * FROM print_station_map WHERE station_id = ?', [$id]) as $r) $m[$r['sector']] = $r;
        return $m;
    }

    public static function setActive(int $id, bool $on): void
    {
        if (!self::get($id)) throw new HttpError(404, 'Estação não encontrada.');
        Db::exec('UPDATE print_stations SET active = ?, armed = 0 WHERE id = ?', [$on ? 1 : 0, $id]);
        if (!$on) Db::exec("UPDATE print_jobs SET status = 'queued', station_id = NULL, reserved_until = NULL WHERE station_id = ? AND status = 'reserved'", [$id]);
        Auth::audit('print.station.' . ($on ? 'on' : 'off'), "estação #$id");
    }

    /**
     * Controle do consumidor. arm = o operador confirmou que o computador e as impressoras estão prontos (zera ao recarregar a página);
     * disarm = para de consumir; pause/resume = pausa administrativa; discard_old = descarta pendentes antigos antes de armar.
     */
    public static function control(int $id, string $action, array $u, bool $discardOld = false): array
    {
        $s = self::get($id);
        if (!$s || !$s['active']) throw new HttpError(403, 'Estação desativada ou inexistente.');
        $dropped = 0;
        if ($action === 'arm') {
            if ($discardOld) $dropped = Db::exec("UPDATE print_jobs SET status = 'failed', last_error = 'Descartado pelo operador ao iniciar a estação (pedido antigo).', finished_at = ? WHERE status = 'queued' AND created_at < ?", [now(), date('Y-m-d H:i:s', time() - self::STALE_JOB_MINUTES * 60)]);
            Db::update('print_stations', $id, ['armed' => 1, 'armed_by' => $u['name'], 'armed_at' => now()]);
        } elseif ($action === 'disarm') Db::update('print_stations', $id, ['armed' => 0]);
        elseif ($action === 'pause') Db::update('print_stations', $id, ['paused' => 1]);
        elseif ($action === 'resume') Db::update('print_stations', $id, ['paused' => 0]);
        else throw new HttpError(422, 'Ação inválida.');
        Auth::audit('print.station.' . $action, "estação #$id" . ($dropped ? " descartou $dropped" : ''));
        return ['ok' => true, 'dropped' => $dropped];
    }

    /** Resumo do que será processado ao armar: nº de pendentes e idade do mais antigo. */
    public static function armPreview(int $id): array
    {
        $map = self::map($id); $sectors = array_keys(array_filter($map, fn($m) => (int)$m['active'] === 1));
        if (!$sectors) return ['pending' => 0, 'old' => 0, 'oldest' => null, 'pages' => 0];
        $ph = implode(',', array_fill(0, count($sectors), '?'));
        $rows = Db::all("SELECT sector, created_at FROM print_jobs WHERE status = 'queued' AND sector IN ($ph) ORDER BY id", $sectors);
        $pages = 0; $old = 0; $limit = time() - self::STALE_JOB_MINUTES * 60;
        foreach ($rows as $r) { $pages += max(1, (int)($map[$r['sector']]['copies'] ?? 1)); if (strtotime($r['created_at']) < $limit) $old++; }
        return ['pending' => count($rows), 'old' => $old, 'oldest' => $rows[0]['created_at'] ?? null, 'pages' => $pages];
    }

    /** Batimento: estado do QZ, impressoras que o Windows expõe (via QZ) e abas abertas. */
    public static function heartbeat(int $id, array $in, array $u): array
    {
        $s = self::get($id);
        if (!$s || !$s['active']) throw new HttpError(403, 'Estação desativada ou inexistente.');
        $row = ['last_seen' => now(), 'last_user' => $u['name'], 'qz_connected' => !empty($in['qz']) ? 1 : 0];
        if (isset($in['version'])) $row['qz_version'] = mb_substr(preg_replace('/[^0-9A-Za-z.\-]/', '', (string)$in['version']), 0, 20);
        if (isset($in['printers']) && is_array($in['printers'])) {
            $p = [];
            foreach (array_slice($in['printers'], 0, 200) as $n) { $n = is_string($n) ? trim(PrintFormatter::clean($n)) : ''; if ($n !== '' && mb_strlen($n) <= 190) $p[] = $n; }
            $row['printers_json'] = json_encode(array_values(array_unique($p)), JSON_UNESCAPED_UNICODE);
        }
        $tab = preg_replace('/[^a-z0-9]/', '', strtolower((string)($in['tab'] ?? '')));
        $inst = array_filter((array)json_decode((string)$s['instances_json'], true), fn($ts) => time() - (int)$ts <= 60);
        if ($tab !== '') $inst[substr($tab, 0, 16)] = time();
        $row['instances_json'] = json_encode($inst);
        Db::update('print_stations', $id, $row);
        return ['ok' => true, 'pending' => (int)Db::val("SELECT COUNT(*) FROM print_jobs WHERE status = 'queued'"), 'tabs' => count($inst), 'armed' => (int)$s['armed'], 'paused' => (int)$s['paused']];
    }

    /** Salva as impressoras da estação (uma por destino). printer '' remove. */
    public static function saveMap(int $id, array $rows, array $u): void
    {
        $s = self::get($id);
        if (!$s) throw new HttpError(404, 'Estação não encontrada.');
        $known = json_decode((string)$s['printers_json'], true) ?: [];
        Db::tx(function () use ($id, $rows, $known) {
            foreach (Printing::SECTORS as $sector => $_) {
                $r = $rows[$sector] ?? null; if ($r === null) continue;
                $printer = trim(PrintFormatter::clean((string)($r['printer'] ?? '')));
                if ($printer === '') { Db::exec('DELETE FROM print_station_map WHERE station_id = ? AND sector = ?', [$id, $sector]); continue; }
                if ($known && !in_array($printer, $known, true)) throw new HttpError(422, 'A fila "' . mb_substr($printer, 0, 60) . '" não foi listada pelo QZ desta estação. Conecte ao QZ e use "Atualizar impressoras".');
                $mode = isset(self::MODES[$r['mode'] ?? '']) ? $r['mode'] : 'escpos';
                $paper = (int)($r['paper'] ?? 80); if (!isset(self::PAPERS[$paper]) || ($paper === 210 && $mode !== 'html')) $paper = 80;
                $cols = (int)($r['cols'] ?? 0); if ($cols < 24 || $cols > 100) $cols = $paper === 58 ? 32 : ($paper === 210 ? 80 : 48);
                $cs = in_array($r['charset'] ?? '', self::CHARSETS, true) ? $r['charset'] : 'ascii';
                $conn = ($r['connection'] ?? 'usb') === 'network' ? 'network' : 'usb';
                $t = fn(string $k, int $n) => mb_substr(trim(PrintFormatter::clean((string)($r[$k] ?? ''))), 0, $n) ?: null;
                Db::exec('DELETE FROM print_station_map WHERE station_id = ? AND sector = ?', [$id, $sector]);
                Db::insert('print_station_map', ['station_id' => $id, 'sector' => $sector, 'printer_name' => $printer, 'cols' => $cols, 'charset' => $cs, 'cut' => $mode === 'escpos' && !empty($r['cut']) ? 1 : 0,
                    'label' => $t('label', 80), 'manufacturer' => $t('manufacturer', 40), 'model' => $t('model', 60), 'connection' => $conn, 'mode' => $mode, 'paper' => $paper,
                    'margin' => max(0, min(10, (int)($r['margin'] ?? 0))), 'copies' => max(1, min(5, (int)($r['copies'] ?? 1))), 'active' => !empty($r['active']) || !isset($r['active']) ? 1 : 0, 'notes' => $t('notes', 200)]);
            }
        });
        Auth::audit('print.station.map', "estação #$id");
    }

    /** Opções do QZ que o servidor espera para o envio (o signer só aceita estas; o resto precisa ser o padrão da biblioteca). */
    public static function qzOptions(array $m): array
    {
        if (($m['mode'] ?? 'escpos') === 'html') return ['units' => 'mm', 'margins' => 3, 'copies' => max(1, (int)$m['copies']), 'scaleContent' => true];
        return [];   // ESC/POS: vias já repetidas nos bytes; opções padrão da biblioteca
    }

    /** Reserva o próximo trabalho dos destinos desta estação. Reentrante. Sem armar, só comandas de teste. */
    public static function claim(int $id): ?array
    {
        $s = self::get($id);
        if (!$s || !$s['active']) throw new HttpError(403, 'Estação desativada ou inexistente.');
        if (Printing::testEnvBlocked() || (int)$s['paused']) return null;
        Printing::sweep();
        $map = array_filter(self::map($id), fn($m) => (int)$m['active'] === 1);
        if (!$map) return null;
        $sectors = array_keys($map); $armed = (int)$s['armed'] === 1;
        return Db::txImmediate(function () use ($id, $map, $sectors, $armed) {
            $now = now();
            $j = Db::one("SELECT * FROM print_jobs WHERE status = 'reserved' AND station_id = ? AND reserved_until >= ? ORDER BY id LIMIT 1", [$id, $now]);
            if (!$j) {
                $ph = implode(',', array_fill(0, count($sectors), '?'));
                $j = Db::one("SELECT * FROM print_jobs WHERE status = 'queued' AND sector IN ($ph)" . ($armed ? '' : " AND kind = 'test'") . ' ORDER BY id LIMIT 1', $sectors);
                if (!$j) return null;
                $n = Db::exec("UPDATE print_jobs SET status = 'reserved', station_id = ?, reserved_until = ? WHERE id = ? AND status = 'queued'", [$id, date('Y-m-d H:i:s', time() + Printing::RESERVE_SECONDS), $j['id']]);
                if ($n !== 1) return null;
            }
            $m = $map[$j['sector']];
            $b = Printing::build($j, ['cols' => (int)$m['cols'], 'charset' => $m['charset'], 'cut' => (bool)$m['cut'], 'mode' => $m['mode'], 'margin' => (int)$m['margin'], 'copies' => (int)$m['copies'], 'paper' => (int)$m['paper']]);
            $hash = hash('sha256', $b['bytes']);
            Db::exec('UPDATE print_jobs SET payload_hash = ?, reserved_until = ? WHERE id = ?', [$hash, date('Y-m-d H:i:s', time() + Printing::RESERVE_SECONDS), $j['id']]);
            return ['uuid' => $j['uuid'], 'sector' => $j['sector'], 'kind' => $j['kind'], 'printer' => $m['printer_name'], 'hash' => $hash, 'mode' => $b['mode'],
                'payload' => $b['mode'] === 'html' ? $b['bytes'] : base64_encode($b['bytes']), 'options' => self::qzOptions($m), 'attempt' => (int)$j['attempts'] + 1];
        });
    }

    /** A estação vai chamar print(): a reserva vira "enviando" (só então a assinatura do envio é liberada). */
    public static function markSending(int $id, string $uuid): array
    {
        $n = Db::txImmediate(fn() => Db::exec("UPDATE print_jobs SET status = 'sending', attempts = attempts + 1, claimed_at = ?, reserved_until = NULL WHERE uuid = ? AND station_id = ? AND status = 'reserved' AND reserved_until >= ?", [now(), $uuid, $id, now()]));
        if ($n !== 1) throw new HttpError(409, 'A reserva deste trabalho expirou ou não é desta estação.');
        return ['ok' => true];
    }

    /** ok = o QZ aceitou o envio; sent = bytes podem ter chegado ao spooler (erro depois do envio => incerto). */
    public static function result(int $id, string $uuid, bool $ok, bool $sent, string $error): array
    {
        $j = Db::one('SELECT * FROM print_jobs WHERE uuid = ? AND station_id = ?', [$uuid, $id]);
        if (!$j) throw new HttpError(404, 'Trabalho não encontrado nesta estação.');
        if ($j['status'] === 'reserved' && !$ok && !$sent) {   // falhou antes de enviar (ex.: fila sumiu): conta a tentativa e volta à fila
            Db::exec('UPDATE print_jobs SET attempts = attempts + 1 WHERE id = ?', [$j['id']]);
            $j['attempts'] = (int)$j['attempts'] + 1;
        } elseif ($j['status'] !== 'sending') return ['status' => $j['status']];
        return Printing::finish($j, $ok, $sent, $error);
    }

    /** Trabalhos recentes e erros (para a tela de fila). */
    public static function recentJobs(int $limit = 40): array
    {
        return Db::all('SELECT j.*, s.name AS station_name FROM print_jobs j LEFT JOIN print_stations s ON s.id = j.station_id ORDER BY j.id DESC LIMIT ' . (int)$limit);
    }

    public static function overview(): array
    {
        return ['stations' => self::all(), 'pendencias' => Printing::pendencias(), 'qz' => QzSigner::status(), 'jobs' => self::recentJobs()];
    }
}
