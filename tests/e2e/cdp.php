<?php
declare(strict_types=1);
/** Cliente mínimo do Chrome DevTools Protocol (WebSocket) para validação local. Fora do projeto arquivado. */
final class Cdp
{
    private $sock; private int $id = 0; public array $events = []; public $handler = null;
    public static function launch(int $port, string $profile, array $extra = []): array
    {
        $chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
        $args = array_merge(["--remote-debugging-port=$port", "--user-data-dir=$profile", '--headless=new', '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--hide-scrollbars=false', 'about:blank'], $extra);
        $list = implode(',', array_map(fn($a) => "'" . str_replace("'", "''", $a) . "'", $args));
        $ps = "Start-Process -FilePath '$chrome' -ArgumentList $list -WindowStyle Hidden";
        exec('powershell -NoProfile -Command "' . str_replace('"', '\\"', $ps) . '"');
        for ($i = 0; $i < 60; $i++) { usleep(250000); try { $j = self::httpGet($port, '/json/version'); } catch (Throwable $e) { continue; } if ($j && ($d = json_decode($j, true))) return $d; }
        throw new RuntimeException('Chrome não iniciou');
    }
    public static function httpGet(int $port, string $path): string
    {
        $s = stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5); stream_set_timeout($s, 5);
        fwrite($s, "GET $path HTTP/1.1\r\nHost: 127.0.0.1:$port\r\nConnection: close\r\n\r\n"); $r = '';
        while (!feof($s)) { $c = fread($s, 8192); if ($c === '' || $c === false) break; $r .= $c; }
        fclose($s); return substr($r, (int)strpos($r, "\r\n\r\n") + 4);
    }
    public static function pageWs(int $port): string
    {
        foreach (json_decode(self::httpGet($port, '/json/list'), true) as $t) if ($t['type'] === 'page') return $t['webSocketDebuggerUrl'];
        throw new RuntimeException('sem página');
    }
    public function __construct(string $ws)
    {
        $u = parse_url($ws); $this->sock = stream_socket_client("tcp://{$u['host']}:{$u['port']}", $en, $es, 10);
        $key = base64_encode(random_bytes(16));
        fwrite($this->sock, "GET {$u['path']} HTTP/1.1\r\nHost: {$u['host']}:{$u['port']}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
        $h = ''; while (!str_contains($h, "\r\n\r\n")) $h .= fread($this->sock, 1);
        if (!str_contains($h, ' 101 ')) throw new RuntimeException('handshake falhou');
        stream_set_timeout($this->sock, 30);
    }
    private function send(string $t): void
    {
        $len = strlen($t); $hdr = "\x81";
        if ($len < 126) $hdr .= chr(0x80 | $len); elseif ($len < 65536) $hdr .= chr(0x80 | 126) . pack('n', $len); else $hdr .= chr(0x80 | 127) . pack('J', $len);
        $mask = random_bytes(4); $m = ''; for ($i = 0; $i < $len; $i++) $m .= $t[$i] ^ $mask[$i % 4];
        fwrite($this->sock, $hdr . $mask . $m);
    }
    private function readN(int $n): string { $b = ''; while (strlen($b) < $n) { $c = fread($this->sock, $n - strlen($b)); if ($c === '' || $c === false) { if (stream_get_meta_data($this->sock)['timed_out']) throw new RuntimeException('timeout'); throw new RuntimeException('conexão fechada'); } $b .= $c; } return $b; }
    private function recv(): ?array
    {
        $msg = '';
        while (true) {
            $h = $this->readN(2); $fin = (ord($h[0]) & 0x80) !== 0; $op = ord($h[0]) & 0x0f; $len = ord($h[1]) & 0x7f;
            if ($len === 126) $len = unpack('n', $this->readN(2))[1]; elseif ($len === 127) $len = unpack('J', $this->readN(8))[1];
            $d = $len ? $this->readN($len) : '';
            if ($op === 9) continue; if ($op === 8) return null;
            $msg .= $d; if ($fin) break;
        }
        return json_decode($msg, true);
    }
    public function call(string $method, array $params = [], float $timeout = 30): array
    {
        $id = ++$this->id; $this->send(json_encode(['id' => $id, 'method' => $method, 'params' => (object)$params]));
        $t0 = microtime(true);
        while (true) {
            $m = $this->recv(); if ($m === null) throw new RuntimeException('fechado');
            if (($m['id'] ?? 0) === $id) { if (isset($m['error'])) throw new RuntimeException($method . ': ' . json_encode($m['error'])); return $m['result'] ?? []; }
            if (isset($m['method'])) { if ($this->handler) ($this->handler)($m, $this); else $this->events[] = $m; }
            if (microtime(true) - $t0 > $timeout) throw new RuntimeException("timeout $method");
        }
    }
    /** Avalia JS e devolve o valor (await suportado). */
    public function js(string $expr, float $timeout = 60)
    {
        $r = $this->call('Runtime.evaluate', ['expression' => $expr, 'awaitPromise' => true, 'returnByValue' => true], $timeout);
        if (isset($r['exceptionDetails'])) throw new RuntimeException('JS: ' . ($r['exceptionDetails']['exception']['description'] ?? json_encode($r['exceptionDetails'])));
        return $r['result']['value'] ?? null;
    }
    public function fire(string $method, array $params = []): void { $this->send(json_encode(['id' => ++$this->id, 'method' => $method, 'params' => (object)$params])); }
    public function pump(float $seconds): void
    {
        $end = microtime(true) + $seconds; stream_set_timeout($this->sock, 1);
        while (microtime(true) < $end) { try { $m = $this->recv(); } catch (RuntimeException $e) { if ($e->getMessage() === 'timeout') continue; throw $e; } if ($m && isset($m['method'])) { if ($this->handler) ($this->handler)($m, $this); else $this->events[] = $m; } }
        stream_set_timeout($this->sock, 30);
    }
    public function navigate(string $url, float $wait = 1.5): void { $this->call('Page.navigate', ['url' => $url]); $this->pump($wait); }
}

/** Credenciais do proprietário de TESTE local (storage/dev-credentials.txt, fora do Git) ou E2E_OWNER_EMAIL/E2E_OWNER_PASSWORD. */
function e2e_owner(): array
{
    $e = getenv('E2E_OWNER_EMAIL') ?: ''; $p = getenv('E2E_OWNER_PASSWORD') ?: '';
    $f = __DIR__ . '/../../storage/dev-credentials.txt';
    if (($e === '' || $p === '') && is_file($f)) foreach (file($f) as $l) { if (preg_match('/^E-mail:\s*(\S+)/', $l, $m)) $e = $e ?: $m[1]; if (preg_match('/^Senha:\s*(\S+)/', $l, $m)) $p = $p ?: $m[1]; }
    if ($e === '' || $p === '') exit("Defina E2E_OWNER_EMAIL/E2E_OWNER_PASSWORD (conta de proprietário de TESTE).\n");
    return [$e, $p];
}
