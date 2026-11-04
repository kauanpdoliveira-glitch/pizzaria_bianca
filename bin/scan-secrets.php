<?php
declare(strict_types=1);
/**
 * Verificação de segredos antes de publicar (sem dependências). Examina:
 *   1) os arquivos que o Git rastreia/vai rastrear (git ls-files + não rastreados não ignorados), e
 *   2) TODO o histórico (git log -p de todas as referências), se houver commits.
 * Procura chaves privadas, tokens (GitHub, AWS, Google, Slack, Stripe), senhas em texto, hashes de senha, JWT, URLs com credenciais,
 * e arquivos que não devem existir (.env real, bancos, dumps, ZIPs, credenciais de desenvolvimento).
 * Uso: php bin/scan-secrets.php      (sai com código 1 se achar algo)
 */
if (PHP_SAPI !== 'cli') exit('Somente via terminal.');
chdir(dirname(__DIR__));
$rules = [
    'chave privada' => '/-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP )?PRIVATE KEY-----/',
    'token GitHub' => '/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{30,}\b|\bgithub_pat_[A-Za-z0-9_]{40,}\b/',
    'chave AWS' => '/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/',
    'chave Google' => '/\bAIza[0-9A-Za-z_\-]{35}\b/',
    'token Slack/Stripe' => '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b|\b(?:sk|rk)_live_[A-Za-z0-9]{16,}\b/',
    'JWT' => '/\beyJ[A-Za-z0-9_-]{15,}\.[A-Za-z0-9_-]{15,}\.[A-Za-z0-9_-]{10,}\b/',
    'hash de senha (bcrypt/argon)' => '/\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}|\$argon2(?:id|i|d)\$[^\s"\']{20,}/',
    'URL com credencial' => '#\b[a-z][a-z0-9+.-]*://[^/\s:@"\']+:[^/\s@"\']{3,}@[^/\s"\']+#i',
    'senha/segredo literal' => '/\b(?:password|passwd|senha|secret|api[_-]?key|token)\b["\']?\s*(?:=>|=|:)\s*["\'][^"\'\s$\{\}<>]{10,}["\']/i',
];
// valores claramente didáticos/placeholders (não são segredos). EstacaoSenha2026/OutraSenha2026: senhas de TESTE de um banco temporário,
// presentes no histórico (commit 8f420ca) por um teste e2e corrigido logo depois; nunca existiram em ambiente real.
$ok = '/(exemplo|example|SEU-|EMAIL_AQUI|SENHA_FORTE|placeholder|xxxx|\*\*\*\*|password_hash\(|\$pw|\$pass|\$tok|senhaforte|abcdefghijklmnopqrstuu|EstacaoSenha2026|OutraSenha2026|\$_POST|\$in\[|getenv|env\()/i';
$badNames = '#(^|/)(\.env|agent\.ini|dev-credentials\.txt)$|\.(sqlite|sqlite3|db|pem|key|p12|pfx|zip|sql\.gz|dump)$|(^|/)storage/(mail|backups|logs)/#';
$allowName = '#(^|/)\.env\.example$#';
$found = [];
function git(string $args): string { return (string)shell_exec('git ' . $args . ' 2>nul'); }
$inRepo = trim(git('rev-parse --is-inside-work-tree')) === 'true';
$files = $inRepo ? array_filter(explode("\n", git('ls-files -co --exclude-standard'))) : [];
if (!$inRepo) { $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.', FilesystemIterator::SKIP_DOTS)); foreach ($it as $f) $files[] = ltrim(str_replace('\\', '/', (string)$f), './'); }
$n = 0;
foreach ($files as $f) {
    $f = trim($f); if ($f === '' || !is_file($f)) continue;
    if (preg_match($badNames, $f) && !preg_match($allowName, $f)) $found[] = ["arquivo proibido", $f, ''];
    if (filesize($f) > 2_000_000 || preg_match('/\.(png|jpe?g|webp|ico|mp4|woff2?|ttf|gif|zip)$/i', $f)) continue;
    $n++; $txt = (string)file_get_contents($f);
    foreach ($rules as $name => $re) if (preg_match_all($re, $txt, $m, PREG_OFFSET_CAPTURE)) foreach ($m[0] as [$hit, $off]) { $line = substr($txt, max(0, strrpos(substr($txt, 0, $off), "\n") ?: 0), 160); if (preg_match($ok, $hit . $line)) continue; $found[] = [$name, $f, substr(trim($hit), 0, 24) . '…']; }
}
$hist = 0;
if ($inRepo && trim(git('rev-list --all --count')) !== '0') {
    $log = git('log --all -p --no-color --format=commit:%H');
    $hist = substr_count($log, "\ncommit:") + 1;
    foreach ($rules as $name => $re) if (preg_match_all($re, $log, $m)) foreach ($m[0] as $hit) { if (preg_match($ok, $hit)) continue; $found[] = ["histórico: $name", '(commits)', substr(trim($hit), 0, 24) . '…']; }
    foreach (array_filter(explode("\n", git('log --all --name-only --pretty=format:'))) as $f) if (preg_match($badNames, $f) && !preg_match($allowName, $f)) $found[] = ['histórico: arquivo proibido', $f, ''];
}
$found = array_unique($found, SORT_REGULAR);
echo "Arquivos de texto examinados: $n · commits no histórico: $hist\n";
foreach ($found as [$what, $where, $hit]) echo "ACHADO  $what  $where  $hit\n";
echo $found ? "\nFALHOU: " . count($found) . " achado(s). Nada deve ser publicado antes de resolver (e, se um segredo real já foi commitado, REVOGUE-O: apagar o arquivo não remove o histórico).\n" : "\nOK: nenhum segredo ou arquivo proibido encontrado.\n";
exit($found ? 1 : 0);
