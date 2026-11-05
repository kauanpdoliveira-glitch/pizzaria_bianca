<?php
declare(strict_types=1);

/** Sessões compartilhadas no banco (SESSION_DRIVER=db): necessárias quando cada requisição pode cair num servidor/instância diferente. */
final class DbSessions implements SessionHandlerInterface
{
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { return (string)(Db::val('SELECT data FROM app_sessions WHERE id = ?', [$id]) ?? ''); }
    public function write(string $id, string $data): bool
    {
        // upsert portável (SQLite 3.24+, PostgreSQL); MySQL usa REPLACE
        if (Db::pdo() && Db::$driver === 'mysql') Db::exec('REPLACE INTO app_sessions (id, data, updated_at) VALUES (?, ?, ?)', [$id, $data, time()]);
        else Db::exec('INSERT INTO app_sessions (id, data, updated_at) VALUES (?, ?, ?) ON CONFLICT (id) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at', [$id, $data, time()]);
        return true;
    }
    public function destroy(string $id): bool { Db::exec('DELETE FROM app_sessions WHERE id = ?', [$id]); return true; }
    public function gc(int $max): int|false { return Db::exec('DELETE FROM app_sessions WHERE updated_at < ?', [time() - $max]); }
}
