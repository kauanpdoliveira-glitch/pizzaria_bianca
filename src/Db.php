<?php
declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;
    public static string $driver = 'sqlite';

    public static function pdo(): PDO
    {
        if (self::$pdo) return self::$pdo;
        self::$driver = env('DB_DRIVER', 'sqlite');
        if (self::$driver === 'mysql') {
            $dsn = 'mysql:host=' . env('DB_HOST', 'localhost') . ';port=' . env('DB_PORT', '3306') . ';dbname=' . env('DB_NAME') . ';charset=utf8mb4';
            $pdo = new PDO($dsn, env('DB_USER'), env('DB_PASS'));
        } else {
            $path = env('DB_SQLITE_PATH', APP_ROOT . '/storage/bianca.sqlite');
            if ($path !== ':memory:' && !is_dir(dirname($path))) @mkdir(dirname($path), 0775, true);
            $pdo = new PDO('sqlite:' . $path);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        return self::$pdo = $pdo;
    }

    public static function reset(): void { self::$pdo = null; }

    public static function all(string $sql, array $p = []): array
    {
        $s = self::pdo()->prepare($sql); $s->execute($p); return $s->fetchAll();
    }
    public static function one(string $sql, array $p = []): ?array
    {
        $s = self::pdo()->prepare($sql); $s->execute($p); $r = $s->fetch(); return $r === false ? null : $r;
    }
    public static function val(string $sql, array $p = [])
    {
        $s = self::pdo()->prepare($sql); $s->execute($p); $r = $s->fetchColumn(); return $r === false ? null : $r;
    }
    public static function exec(string $sql, array $p = []): int
    {
        $s = self::pdo()->prepare($sql); $s->execute($p); return $s->rowCount();
    }
    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::exec($sql, array_values($row));
        return (int)self::pdo()->lastInsertId();
    }
    public static function update(string $table, int $id, array $row): void
    {
        $set = implode(',', array_map(fn($c) => "$c = ?", array_keys($row)));
        self::exec("UPDATE $table SET $set WHERE id = ?", [...array_values($row), $id]);
    }
    public static function tx(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try { $r = $fn(); $pdo->commit(); return $r; }
        catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /** Transação que já nasce com bloqueio de escrita (SQLite: BEGIN IMMEDIATE; MySQL: o chamador usa SELECT ... FOR UPDATE). Serializa aberturas concorrentes. */
    public static function txImmediate(callable $fn)
    {
        if (self::$driver !== 'sqlite') return self::tx($fn);
        $pdo = self::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try { $r = $fn(); $pdo->exec('COMMIT'); return $r; }
        catch (Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) { /* pdo_sqlite não informa inTransaction() para BEGIN manual */ } throw $e; }
    }

    /** Aplica migrations/*.sql ainda não registradas. Tokens: {{PK}} e {{ENGINE}}. */
    public static function migrate(): array
    {
        $pdo = self::pdo();
        $mysql = self::$driver === 'mysql';
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(100) PRIMARY KEY, applied_at VARCHAR(19) NOT NULL)');
        $done = array_column(self::all('SELECT name FROM schema_migrations'), 'name');
        $applied = [];
        $files = glob(APP_ROOT . '/migrations/*.sql');
        sort($files);
        foreach ($files as $f) {
            $name = basename($f);
            if (in_array($name, $done, true)) continue;
            $sql = str_replace(
                ['{{PK}}', '{{ENGINE}}'],
                $mysql ? ['INT UNSIGNED AUTO_INCREMENT PRIMARY KEY', ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci']
                       : ['INTEGER PRIMARY KEY AUTOINCREMENT', ''],
                file_get_contents($f)
            );
            foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $stmt) $pdo->exec($stmt);
            self::exec('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)', [$name, now()]);
            $applied[] = $name;
        }
        return $applied;
    }
}
