<?php
/** Servidor SMTP FALSO só para testes: aceita UMA mensagem (porta e arquivo de saída por argumento) e grava o diálogo. Nunca é usado fora dos testes. */
[$_, $port, $out] = $argv + [null, '2526', 'smtp-out.txt'];
$srv = stream_socket_server("tcp://127.0.0.1:$port", $en, $es); if (!$srv) exit(1);
$c = stream_socket_accept($srv, 20); if (!$c) exit(2);
$log = ''; $w = function (string $s) use ($c, &$log) { fwrite($c, $s . "\r\n"); $log .= "S: $s\n"; };
$w('220 fake ESMTP');
$data = false; $msg = '';
while (($l = fgets($c)) !== false) {
    $log .= 'C: ' . $l;
    if ($data) { if (rtrim($l) === '.') { $data = false; $w('250 queued'); } else $msg .= $l; continue; }
    $u = strtoupper(trim($l));
    if (str_starts_with($u, 'EHLO')) $w('250 fake');
    elseif ($u === 'AUTH LOGIN') $w('334 VXNlcm5hbWU6');
    elseif (str_starts_with($u, 'MAIL FROM') || str_starts_with($u, 'RCPT TO')) $w('250 ok');
    elseif ($u === 'DATA') { $w('354 go'); $data = true; }
    elseif ($u === 'QUIT') { $w('221 bye'); break; }
    else { static $n = 0; $n++; $w($n === 1 ? '334 UGFzc3dvcmQ6' : '235 authenticated'); }
}
file_put_contents($out, $log . "\n--MSG--\n" . $msg);
