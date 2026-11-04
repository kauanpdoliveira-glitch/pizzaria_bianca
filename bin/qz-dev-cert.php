<?php
declare(strict_types=1);
/**
 * Gera um par chave/certificado AUTOASSINADO para TESTES do QZ Tray (nunca para produção).
 *   php bin/qz-dev-cert.php [--force]
 * Grava storage/qz/private-key.pem (PKCS#8, 2048 bits, fora de public/ e ignorado pelo Git) e storage/qz/digital-certificate.txt.
 * Confiança: o QZ Tray só aceita este certificado se ele for importado manualmente no Site Manager do QZ (ambiente de teste).
 * Impressão SILENCIOSA em produção exige certificado confiável pelo QZ (portal do QZ ou override.crt gerenciado pela loja) — ver docs/IMPRESSAO.md.
 */
if (PHP_SAPI !== 'cli') exit('Somente via terminal.');
$dir = dirname(__DIR__) . '/storage/qz';
$key = $dir . '/private-key.pem'; $crt = $dir . '/digital-certificate.txt';
if ((is_file($key) || is_file($crt)) && !in_array('--force', $argv, true)) exit("Já existe par em storage/qz/. Use --force para substituir.\n");
if (!is_dir($dir)) mkdir($dir, 0700, true);
$cfg = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha512'];
if (!getenv('OPENSSL_CONF')) {   // PHP no Windows muitas vezes não acha o openssl.cnf: usa um mínimo temporário
    $tmp = sys_get_temp_dir() . '/bianca-openssl-' . bin2hex(random_bytes(4)) . '.cnf';
    file_put_contents($tmp, "[req]\ndistinguished_name=dn\n[dn]\n[v3_ca]\nbasicConstraints=critical,CA:FALSE\n");
    register_shutdown_function(fn() => @unlink($tmp));
    $cfg['config'] = $tmp;
}
$pk = openssl_pkey_new($cfg);
if (!$pk) exit("Falha ao gerar a chave: " . openssl_error_string() . "\n");
$dn = ['commonName' => 'Bianca Pizzaria - TESTE QZ (autoassinado)', 'organizationName' => 'Bianca Pizzaria', 'countryName' => 'BR'];
$csr = openssl_csr_new($dn, $pk, $cfg);
$x = openssl_csr_sign($csr, null, $pk, 825, $cfg);
openssl_x509_export($x, $certPem); openssl_pkey_export($pk, $keyPem, null, $cfg);
file_put_contents($key, $keyPem); @chmod($key, 0600); file_put_contents($crt, $certPem);
echo "Gerado: storage/qz/private-key.pem (segredo: não compartilhe nem versione) e storage/qz/digital-certificate.txt (público).\n";
echo "Cabeçalho da chave: " . strtok($keyPem, "\n") . "\n";
