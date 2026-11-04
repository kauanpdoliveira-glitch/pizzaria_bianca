<?php
declare(strict_types=1);
/**
 * Gera um pacote de confiança PRÓPRIO para o QZ Tray (homologação / uso interno da loja):
 *   - uma autoridade raiz local (CA) -> storage/qz/override.crt  (público; é o arquivo que se instala no computador da estação)
 *   - um certificado de assinatura assinado por essa CA -> storage/qz/digital-certificate.txt (público; servido ao QZ pelo backend)
 *   - a chave privada do certificado (PKCS#8, 2048 bits) -> storage/qz/private-key.pem (SEGREDO: só no servidor)
 *   php bin/qz-trust-bundle.php [--force]
 * A chave da CA é gravada em storage/qz/ca-key.pem só para renovações; depois de gerar, GUARDE-A offline e apague-a do servidor.
 * IMPORTANTE: para o QZ Tray aceitar este certificado SEM pedir confirmação por trabalho, o override.crt precisa ser instalado e
 * reconhecido pelo QZ no computador da estação (ver docs/IMPRESSAO.md). A documentação oficial do QZ cobre a assinatura e o certificado
 * confiável; o uso de override.crt com uma CA própria não está detalhado na documentação oficial consultada — o resultado é conferido
 * na prática, no computador real, e só então registrado como "silencioso".
 * Nunca versione storage/qz/ (já ignorado pelo Git).
 */
if (PHP_SAPI !== 'cli') exit('Somente via terminal.');
$dir = dirname(__DIR__) . '/storage/qz';
$files = ['override.crt', 'digital-certificate.txt', 'private-key.pem', 'ca-key.pem'];
if (!in_array('--force', $argv, true)) foreach ($files as $f) if (is_file("$dir/$f")) exit("Já existe storage/qz/$f. Use --force para substituir o conjunto (a estação precisará confiar de novo).\n");
if (!is_dir($dir)) mkdir($dir, 0700, true);
$cnf = sys_get_temp_dir() . '/bianca-qz-' . bin2hex(random_bytes(4)) . '.cnf';
file_put_contents($cnf, "[req]\ndistinguished_name=dn\n[dn]\n[v3_ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n[v3_leaf]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=codeSigning\nsubjectKeyIdentifier=hash\n");
register_shutdown_function(fn() => @unlink($cnf));
$base = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha512', 'config' => $cnf];
$caKey = openssl_pkey_new($base);
$caCsr = openssl_csr_new(['commonName' => 'Bianca Pizzaria - CA local de homologacao', 'organizationName' => 'Bianca Pizzaria', 'countryName' => 'BR'], $caKey, $base + ['req_extensions' => 'v3_ca']);
$caCrt = openssl_csr_sign($caCsr, null, $caKey, 3650, $base + ['x509_extensions' => 'v3_ca']);
$leafKey = openssl_pkey_new($base);
$leafCsr = openssl_csr_new(['commonName' => 'Bianca Pizzaria - assinatura QZ (homologacao)', 'organizationName' => 'Bianca Pizzaria', 'countryName' => 'BR'], $leafKey, $base);
$leafCrt = openssl_csr_sign($leafCsr, $caCrt, $caKey, 1095, $base + ['x509_extensions' => 'v3_leaf']);
if (!$caCrt || !$leafCrt) exit('Falha ao gerar certificados: ' . openssl_error_string() . "\n");
openssl_x509_export($caCrt, $caPem); openssl_x509_export($leafCrt, $leafPem); openssl_pkey_export($leafKey, $leafKeyPem, null, $base); openssl_pkey_export($caKey, $caKeyPem, null, $base);
file_put_contents("$dir/override.crt", $caPem); file_put_contents("$dir/digital-certificate.txt", $leafPem); file_put_contents("$dir/private-key.pem", $leafKeyPem); file_put_contents("$dir/ca-key.pem", $caKeyPem);
foreach (['private-key.pem', 'ca-key.pem'] as $f) @chmod("$dir/$f", 0600);
echo "Gerado em storage/qz/:\n  override.crt (CA pública: instalar no computador da estação)\n  digital-certificate.txt (certificado público de assinatura)\n  private-key.pem (SEGREDO do servidor)\n  ca-key.pem (SEGREDO: guarde offline e apague do servidor)\n";
echo 'Validade do certificado de assinatura: ' . date('d/m/Y', (int)openssl_x509_parse($leafPem)['validTo_time_t']) . "\n";
echo 'Cadeia confere: ' . (openssl_x509_verify($leafCrt, openssl_pkey_get_public($caPem)) === 1 ? 'sim' : 'NAO') . "\n";
