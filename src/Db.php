<?php
declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;
    public static string $driver = 'sqlite';
    private static array $hasId = [];

    public static function pdo(): PDO
    {
        if (self::$pdo) return self::$pdo;
        self::$driver = env('DB_DRIVER', 'sqlite');
        if (self::$driver === 'mysql') {
            $dsn = 'mysql:host=' . env('DB_HOST', 'localhost') . ';port=' . env('DB_PORT', '3306') . ';dbname=' . env('DB_NAME') . ';charset=utf8mb4';
            $pdo = new PDO($dsn, env('DB_USER'), env('DB_PASS'));
        } elseif (self::$driver === 'pgsql') {
            // PostgreSQL (ex.: Supabase). Pooler em modo "transaction" não aceita prepared statements no servidor => emulação (abaixo).
            // TLS obrigatório por padrão (sslmode=require). Timeout de conexão curto para não segurar a função serverless.
            $dsn = 'pgsql:host=' . env('DB_HOST') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_NAME', 'postgres') . ';sslmode=' . env('DB_SSLMODE', 'require') . ';connect_timeout=10';
            $pdo = new PDO($dsn, env('DB_USER'), env('DB_PASS'), [PDO::ATTR_PERSISTENT => false]);
            $schema = (string)env('DB_SCHEMA', '');
            if ($schema !== '') {   // esquema alternativo (testes isolados); só nomes simples
                if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/', $schema)) throw new RuntimeException('DB_SCHEMA inválido.');
                $pdo->exec('SET search_path TO "' . $schema . '"');
            }
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
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, self::$driver === 'pgsql');
        return self::$pdo = $pdo;
    }

    public static function reset(): void { self::$pdo = null; self::$hasId = []; }

    /** Operador de busca sem diferenciar maiúsculas (LIKE já é assim no SQLite/MySQL; no PostgreSQL é ILIKE). */
    public static function like(): string { self::pdo(); return self::$driver === 'pgsql' ? 'ILIKE' : 'LIKE'; }

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
        if (self::pdo() && self::$driver === 'pgsql') {
            // PostgreSQL: lastInsertId() depende de sequência/sessão (inseguro atrás de pooler). RETURNING id é atômico.
            if (self::tableHasId($table) && !array_key_exists('id', $row)) { $s = self::pdo()->prepare($sql . ' RETURNING id'); $s->execute(array_values($row)); return (int)$s->fetchColumn(); }
            self::exec($sql, array_values($row)); return 0;
        }
        self::exec($sql, array_values($row));
        return (int)self::pdo()->lastInsertId();
    }
    private static function tableHasId(string $table): bool
    {
        return self::$hasId[$table] ??= (bool)self::val("SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = 'id' AND column_default IS NOT NULL", [$table]);
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

    /** Transação que já nasce com bloqueio de escrita (SQLite: BEGIN IMMEDIATE; MySQL/PostgreSQL: transação normal — a reserva usa UPDATE condicional atômico). */
    public static function txImmediate(callable $fn)
    {
        self::pdo();
        if (self::$driver !== 'sqlite') return self::tx($fn);
        $pdo = self::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try { $r = $fn(); $pdo->exec('COMMIT'); return $r; }
        catch (Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) { /* pdo_sqlite não informa inTransaction() para BEGIN manual */ } throw $e; }
    }

    /** Traduz os tokens/tipos das migrations (escritas em estilo SQLite/MySQL) para o banco em uso. */
    public static function translate(string $sql, ?string $driver = null): string
    {
        if ($driver === null) { self::pdo(); $driver = self::$driver; }
        if ($driver === 'mysql') return str_replace(['{{PK}}', '{{ENGINE}}'], ['INT UNSIGNED AUTO_INCREMENT PRIMARY KEY', ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'], $sql);
        if ($driver === 'pgsql') {
            $sql = str_replace(['{{PK}}', '{{ENGINE}}'], ['SERIAL PRIMARY KEY', ''], $sql);
            return (string)preg_replace(['/\bINT UNSIGNED\b/', '/\bTINYINT\b/', '/\bLONGBLOB\b/', '/\bMEDIUMTEXT\b/'], ['INTEGER', 'SMALLINT', 'TEXT', 'TEXT'], $sql);   // imagens no banco ficam em base64 (TEXT) no PostgreSQL
        }
        return str_replace(['{{PK}}', '{{ENGINE}}'], ['INTEGER PRIMARY KEY AUTOINCREMENT', ''], $sql);
    }

    /** Aplica migrations/*.sql ainda não registradas (procedimento controlado: bin/migrate.php, nunca a cada requisição). */
    public static function migrate(): array
    {
        $pdo = self::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(100) PRIMARY KEY, applied_at VARCHAR(19) NOT NULL)');
        $done = array_column(self::all('SELECT name FROM schema_migrations'), 'name');
        $applied = [];
        $files = glob(APP_ROOT . '/migrations/*.sql');
        sort($files);
        foreach ($files as $f) {
            $name = basename($f);
            if (in_array($name, $done, true)) continue;
            $sql = self::translate((string)file_get_contents($f));
            if (self::$driver === 'pgsql') $pdo->beginTransaction();   // DDL transacional: uma migration falha por inteiro ou nada
            try {
                foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $stmt) $pdo->exec($stmt);
                self::exec('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)', [$name, now()]);
                if (self::$driver === 'pgsql') $pdo->commit();
            } catch (Throwable $e) { if (self::$driver === 'pgsql' && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            $applied[] = $name;
        }
        return $applied;
    }
}
