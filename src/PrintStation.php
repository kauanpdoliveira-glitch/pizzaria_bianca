<?php
declare(strict_types=1);

/**
 * Estações de impressão (navegador autorizado da loja + QZ Tray local).
 * - A estação é um navegador logado na loja (perfil com permissão "print") com o QZ Tray rodando no mesmo computador.
 * - Clientes e garçons NUNCA imprimem direto: pedidos entram na fila do servidor; só a estação reserva e envia.
 * - Cada trabalho é reservado por UMA estação (UPDATE atômico) com validade curta; só depois de "sending" a assinatura do envio é liberada.
 * - Configurar estações e mapear impressoras exige a permissão "settings" (somente o proprietário).
 */
final class PrintStation
{
    public const ONLINE_SECONDS = 45;
    public const CHARSETS = ['ascii', 'cp860', 'cp850', 'cp1252'];

    public static function create(string $name, array $u): int
    {
        $name = trim(PrintFormatter::clean($name));
        if ($name === '' || mb_strlen($name) > 80) throw new HttpError(422, 'Informe um nome de até 80 caracteres.');
        $id = Db::insert('print_stations', ['name' => $name, 'active' => 1, 'created_by' => $u['name'], 'created_at' => now()]);
        Auth::audit('print.station.create', "estação #$id $name");
        return $id;
    }

    public static function get(int $id): ?array { return Db::one('SELECT * FROM print_stations WHERE id = ?', [$id]); }

    public static function online(array $s): bool { return $s['last_seen'] && time() - strtotime($s['last_seen']) <= self::ONLINE_SECONDS && (int)$s['qz_connected'] === 1; }

    /** @return list<array> estações com mapa e estado */
    public static function all(): array
    {
        $out = [];
        foreach (Db::all('SELECT * FROM print_stations ORDER BY id') as $s) {
            $s['printers'] = json_decode((string)$s['printers_json'], true) ?: [];
            $s['map'] = self::map((int)$s['id']);
            $s['online'] = self::online($s);
            $out[] = $s;
        }
        return $out;
    }

    /** @return array<string,array> mapa por destino */
    public static function map(int $id): array
    {
        $m = [];
        foreach (Db::all('SELECT * FROM print_station_map WHERE station_id = ?', [$id]) as $r) $m[$r['sector']] = $r;
        return $m;
    }

    public static function setActive(int $id, bool $on): void
    {
        if (!self::get($id)) throw new HttpError(404, 'Estação não encontrada.');
        Db::exec('UPDATE print_stations SET active = ? WHERE id = ?', [$on ? 1 : 0, $id]);
        if (!$on) Db::exec("UPDATE print_jobs SET status = 'queued', station_id = NULL, reserved_until = NULL WHERE station_id = ? AND status = 'reserved'", [$id]);
        Auth::audit('print.station.' . ($on ? 'on' : 'off'), "estação #$id");
    }

    /** Batimento: a estação informa o estado do QZ e as impressoras que o sistema operacional expõe. */
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
        Db::update('print_stations', $id, $row);
        return ['ok' => true, 'pending' => (int)Db::val("SELECT COUNT(*) FROM print_jobs WHERE status = 'queued'")];
    }

    /** Salva o mapa destino → impressora. printer '' remove o mapeamento. */
    public static function saveMap(int $id, array $rows, array $u): void
    {
        $s = self::get($id);
        if (!$s) throw new HttpError(404, 'Estação não encontrada.');
        $known = json_decode((string)$s['printers_json'], true) ?: [];
        Db::tx(function () use ($id, $rows, $known) {
            foreach (Printing::SECTORS as $sector => $_) {
                $r = $rows[$sector] ?? null; if ($r === null) continue;
                $printer = trim((string)($r['printer'] ?? ''));
                if ($printer === '') { Db::exec('DELETE FROM print_station_map WHERE station_id = ? AND sector = ?', [$id, $sector]); continue; }
                if ($known && !in_array($printer, $known, true)) throw new HttpError(422, 'A impressora "' . mb_substr($printer, 0, 60) . '" não foi informada por esta estação. Abra a estação e aguarde o QZ listar as impressoras.');
                $cols = (int)($r['cols'] ?? 48); $cols = $cols >= 24 && $cols <= 80 ? $cols : 48;
                $cs = in_array($r['charset'] ?? 'ascii', self::CHARSETS, true) ? $r['charset'] : 'ascii';
                Db::exec('DELETE FROM print_station_map WHERE station_id = ? AND sector = ?', [$id, $sector]);
                Db::insert('print_station_map', ['station_id' => $id, 'sector' => $sector, 'printer_name' => $printer, 'cols' => $cols, 'charset' => $cs, 'cut' => !empty($r['cut']) ? 1 : 0]);
            }
        });
        Auth::audit('print.station.map', "estação #$id");
    }

    /** Reserva o próximo trabalho dos destinos mapeados nesta estação. Reentrante: devolve a reserva ainda válida da própria estação. */
    public static function claim(int $id): ?array
    {
        $s = self::get($id);
        if (!$s || !$s['active']) throw new HttpError(403, 'Estação desativada ou inexistente.');
        if (Printing::testEnvBlocked()) return null;
        Printing::sweep();
        $map = self::map($id);
        if (!$map) return null;
        $sectors = array_keys($map);
        return Db::txImmediate(function () use ($id, $map, $sectors) {
            $now = now();
            $j = Db::one("SELECT * FROM print_jobs WHERE status = 'reserved' AND station_id = ? AND reserved_until >= ? ORDER BY id LIMIT 1", [$id, $now]);
            if (!$j) {
                $ph = implode(',', array_fill(0, count($sectors), '?'));
                $j = Db::one("SELECT * FROM print_jobs WHERE status = 'queued' AND sector IN ($ph) ORDER BY id LIMIT 1", $sectors);
                if (!$j) return null;
                $n = Db::exec("UPDATE print_jobs SET status = 'reserved', station_id = ?, reserved_until = ? WHERE id = ? AND status = 'queued'", [$id, date('Y-m-d H:i:s', time() + Printing::RESERVE_SECONDS), $j['id']]);
                if ($n !== 1) return null;
            }
            $m = $map[$j['sector']];
            $b = Printing::build($j, ['cols' => (int)$m['cols'], 'charset' => $m['charset'], 'cut' => (bool)$m['cut']]);
            $hash = hash('sha256', $b['bytes']);
            Db::exec('UPDATE print_jobs SET payload_hash = ?, reserved_until = ? WHERE id = ?', [$hash, date('Y-m-d H:i:s', time() + Printing::RESERVE_SECONDS), $j['id']]);
            return ['uuid' => $j['uuid'], 'sector' => $j['sector'], 'kind' => $j['kind'], 'printer' => $m['printer_name'], 'hash' => $hash, 'bytes_b64' => base64_encode($b['bytes']), 'attempt' => (int)$j['attempts'] + 1];
        });
    }

    /** A estação vai chamar print(): a reserva vira "enviando" (só então a assinatura do envio é liberada). */
    public static function markSending(int $id, string $uuid): array
    {
        $n = Db::txImmediate(fn() => Db::exec("UPDATE print_jobs SET status = 'sending', attempts = attempts + 1, claimed_at = ?, reserved_until = NULL WHERE uuid = ? AND station_id = ? AND status = 'reserved' AND reserved_until >= ?", [now(), $uuid, $id, now()]));
        if ($n !== 1) throw new HttpError(409, 'A reserva deste trabalho expirou ou não é desta estação.');
        return ['ok' => true];
    }

    /**
     * Resultado informado pela estação. ok = o QZ aceitou o envio; sent = bytes podem ter chegado ao spooler (erro depois do envio => incerto).
     */
    public static function result(int $id, string $uuid, bool $ok, bool $sent, string $error): array
    {
        $j = Db::one('SELECT * FROM print_jobs WHERE uuid = ? AND station_id = ?', [$uuid, $id]);
        if (!$j) throw new HttpError(404, 'Trabalho não encontrado nesta estação.');
        if ($j['status'] === 'reserved' && !$ok && !$sent) {   // falhou antes de enviar (ex.: impressora sumiu): conta a tentativa e volta à fila
            Db::exec('UPDATE print_jobs SET attempts = attempts + 1 WHERE id = ?', [$j['id']]);
            $j['attempts'] = (int)$j['attempts'] + 1;
        } elseif ($j['status'] !== 'sending') return ['status' => $j['status']];
        return Printing::finish($j, $ok, $sent, $error);
    }

    /** Painel: estações, fila e pendências. */
    public static function overview(): array
    {
        return ['stations' => self::all(), 'pendencias' => Printing::pendencias(), 'qz' => QzSigner::status()];
    }
}
