<?php
declare(strict_types=1);

/** Turnos de caixa: abertura, suprimento/sangria, fechamento com valor esperado × informado. Um único turno aberto (garantido pelo banco). */
final class Cash
{
    public static function currentId(): ?int
    {
        $id = Db::val('SELECT id FROM cash_shifts WHERE closed_at IS NULL ORDER BY id DESC LIMIT 1');
        return $id === null ? null : (int)$id;
    }

    public static function open(int $openingCents, array $user): int
    {
        Floor::need($user, 'cashier');
        if ($openingCents < 0) throw new HttpError(422, 'Valor de abertura inválido.');
        if (self::currentId()) throw new HttpError(409, 'Já existe um turno aberto.');
        try { $id = Db::insert('cash_shifts', ['opened_by' => $user['id'], 'opened_by_name' => $user['name'], 'opened_at' => now(), 'opening_cents' => $openingCents, 'open_key' => 1]); }
        catch (PDOException $e) { throw new HttpError(409, 'Já existe um turno aberto.'); }
        Db::insert('audit_log', ['user_id' => $user['id'], 'user_name' => $user['name'], 'action' => 'cash.open', 'detail' => "turno #$id abertura " . money($openingCents), 'created_at' => now()]);
        return $id;
    }

    public static function move(string $kind, int $amount, string $reason, array $user): void
    {
        Floor::need($user, 'cashier');
        if (!in_array($kind, ['supply', 'withdraw'], true)) throw new HttpError(422, 'Tipo inválido.');
        if ($amount < 1) throw new HttpError(422, 'Informe um valor maior que zero.');
        if (mb_strlen(trim($reason)) < 3) throw new HttpError(422, 'Informe o motivo.');
        $sid = self::currentId();
        if (!$sid) throw new HttpError(422, 'Abra um turno antes.');
        Db::insert('cash_moves', ['shift_id' => $sid, 'kind' => $kind, 'amount_cents' => $amount, 'reason' => mb_substr(trim($reason), 0, 300), 'by_id' => $user['id'], 'by_name' => $user['name'], 'created_at' => now()]);
        Db::insert('audit_log', ['user_id' => $user['id'], 'user_name' => $user['name'], 'action' => 'cash.' . $kind, 'detail' => "turno #$sid " . money($amount) . ' — ' . $reason, 'created_at' => now()]);
    }

    /** Dinheiro esperado em caixa = abertura + recebimentos em dinheiro confirmados + suprimentos − sangrias. */
    public static function summary(int $shiftId): array
    {
        $s = Db::one('SELECT * FROM cash_shifts WHERE id = ?', [$shiftId]);
        if (!$s) throw new HttpError(404, 'Turno não encontrado.');
        $byMethod = Db::all("SELECT method, COUNT(*) AS n, SUM(amount_cents) AS total FROM tab_payments WHERE shift_id = ? AND status = 'confirmed' GROUP BY method ORDER BY total DESC", [$shiftId]);
        $cash = (int)Db::val("SELECT COALESCE(SUM(amount_cents),0) FROM tab_payments WHERE shift_id = ? AND status = 'confirmed' AND method = 'cash'", [$shiftId]);
        $sup = (int)Db::val("SELECT COALESCE(SUM(amount_cents),0) FROM cash_moves WHERE shift_id = ? AND kind = 'supply'", [$shiftId]);
        $wd = (int)Db::val("SELECT COALESCE(SUM(amount_cents),0) FROM cash_moves WHERE shift_id = ? AND kind = 'withdraw'", [$shiftId]);
        return ['shift' => $s, 'by_method' => $byMethod, 'cash_in' => $cash, 'supplies' => $sup, 'withdrawals' => $wd,
            'expected' => (int)$s['opening_cents'] + $cash + $sup - $wd, 'moves' => Db::all('SELECT * FROM cash_moves WHERE shift_id = ? ORDER BY id', [$shiftId])];
    }

    public static function close(int $countedCents, string $note, array $user): array
    {
        Floor::need($user, 'cashier');
        if ($countedCents < 0) throw new HttpError(422, 'Valor contado inválido.');
        $sid = self::currentId();
        if (!$sid) throw new HttpError(422, 'Não há turno aberto.');
        $pending = (int)Db::val("SELECT COUNT(*) FROM tab_payments WHERE status = 'reported'");
        if ($pending) throw new HttpError(422, "Há $pending recebimento(s) informado(s) aguardando conferência. Confirme ou recuse antes de fechar o turno.");
        $sum = self::summary($sid); $diff = $countedCents - $sum['expected'];
        if ($diff !== 0 && mb_strlen(trim($note)) < 3) throw new HttpError(422, 'Há diferença de ' . money(abs($diff)) . ': informe uma observação.');
        Db::exec('UPDATE cash_shifts SET closed_by = ?, closed_by_name = ?, closed_at = ?, expected_cents = ?, counted_cents = ?, diff_cents = ?, close_note = ?, open_key = NULL WHERE id = ?',
            [$user['id'], $user['name'], now(), $sum['expected'], $countedCents, $diff, mb_substr(trim($note), 0, 300) ?: null, $sid]);
        Db::insert('audit_log', ['user_id' => $user['id'], 'user_name' => $user['name'], 'action' => 'cash.close', 'detail' => "turno #$sid esperado " . money($sum['expected']) . ' contado ' . money($countedCents) . ' diferença ' . money($diff), 'created_at' => now()]);
        return ['expected' => $sum['expected'], 'counted' => $countedCents, 'diff' => $diff];
    }
}
