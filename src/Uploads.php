<?php
declare(strict_types=1);

/** Armazenamento das imagens enviadas pelo painel: pasta public/uploads (padrão) ou banco (UPLOAD_DRIVER=db, para hospedagem sem disco persistente). */
final class Uploads
{
    public static function driver(): string { return env('UPLOAD_DRIVER', 'local') === 'db' ? 'db' : 'local'; }

    /** Grava um JPEG já processado e devolve o caminho público. */
    public static function put(string $jpeg): string
    {
        $name = bin2hex(random_bytes(8)) . '.jpg';
        if (self::driver() === 'db') {
            Db::insert('uploaded_files', ['name' => $name, 'mime' => 'image/jpeg', 'data' => $jpeg, 'created_at' => now()]);
            return "/uploads/$name";
        }
        $dir = APP_ROOT . '/public/uploads';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (!is_dir($dir) || !is_writable($dir)) throw new HttpError(503, 'Armazenamento de imagens indisponível neste ambiente (disco somente leitura). Configure UPLOAD_DRIVER=db.');
        file_put_contents("$dir/$name", $jpeg);
        return "/uploads/$name";
    }

    /** @return array{mime:string,data:string}|null */
    public static function get(string $name): ?array
    {
        if (!preg_match('/^[a-f0-9]{16}\.jpg$/', $name)) return null;
        $r = Db::one('SELECT mime, data FROM uploaded_files WHERE name = ?', [$name]);
        if (!$r) return null;
        if (is_resource($r['data'])) $r['data'] = stream_get_contents($r['data']);
        return ['mime' => $r['mime'], 'data' => (string)$r['data']];
    }
}
