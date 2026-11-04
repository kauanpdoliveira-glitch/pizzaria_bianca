# Teste do telefone: app local + tunel temporario (Cloudflare Quick Tunnel). Somente TESTE: banco proprio, faixa de ambiente de testes, noindex.
# Uso (PowerShell, na pasta do projeto):   powershell -ExecutionPolicy Bypass -File bin\teste-tunel.ps1 [-CloudflaredPath C:\caminho\cloudflared.exe] [-Port 8080]
# Requisitos: PHP 8.2+ no PATH e cloudflared.exe baixado do distribuidor oficial (github.com/cloudflare/cloudflared/releases).
# O que faz: cria/usa storage\teste-tunel.sqlite (ignorado pelo Git), importa o catalogo, cria um proprietario de teste com senha aleatoria
# (mostrada UMA vez aqui), abre o tunel, sobe o servidor PHP com APP_ENV=staging e PRINT_ENABLED=1 e mostra a URL publica para o telefone.
# Para parar: Ctrl+C. A URL deixa de funcionar e muda a cada execucao. Nada e publicado em hospedagem; nenhum dado real.
param([string]$CloudflaredPath = '', [int]$Port = 8080)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root
if (-not (Get-Command php -ErrorAction SilentlyContinue)) { throw 'PHP nao encontrado no PATH.' }
if (-not $CloudflaredPath) { $cand = @("$root\..\Pizzaria_Bianca-ferramentas\cloudflared.exe", "$env:ProgramFiles\cloudflared\cloudflared.exe"); $CloudflaredPath = $cand | Where-Object { Test-Path $_ } | Select-Object -First 1 }
if (-not $CloudflaredPath -or -not (Test-Path $CloudflaredPath)) { throw 'cloudflared.exe nao encontrado. Baixe do site oficial e passe -CloudflaredPath.' }

$env:DB_DRIVER = 'sqlite'; $env:DB_SQLITE_PATH = "$root\storage\teste-tunel.sqlite"; $env:APP_ENV = 'staging'; $env:PRINT_ENABLED = '1'; $env:MAIL_DRIVER = 'log'
$novo = -not (Test-Path $env:DB_SQLITE_PATH)
php bin\migrate.php | Out-Null
php bin\teste-config.php | Out-Null
if ($novo) {
    php bin\restore.php database\export\catalogo-2026-11-04.json | Out-Null
    $senha = 'Teste' + (-join ((48..57) + (97..122) | Get-Random -Count 10 | ForEach-Object { [char]$_ }))
    $senha | php bin\create-admin.php dono@teste.local 'Dono Teste' | Out-Null
    Write-Host "Proprietario de teste: dono@teste.local  senha: $senha   (anote agora; so aparece esta vez)" -ForegroundColor Yellow
} else { Write-Host 'Banco de teste ja existe (storage\teste-tunel.sqlite); senha anterior continua valida.' }

$log = Join-Path $env:TEMP 'bianca-tunel.log'; Remove-Item $log -ErrorAction SilentlyContinue
$tun = Start-Process -FilePath $CloudflaredPath -ArgumentList @('tunnel', '--no-autoupdate', '--url', "http://127.0.0.1:$Port", '--logfile', $log) -PassThru -WindowStyle Hidden
$url = $null
for ($i = 0; $i -lt 60 -and -not $url; $i++) { Start-Sleep 1; if (Test-Path $log) { $m = Select-String -Path $log -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' | Select-Object -First 1; if ($m) { $url = $m.Matches[0].Value } } }
if (-not $url) { Stop-Process -Id $tun.Id -Force; throw "Nao consegui obter a URL do tunel (veja $log)." }
$env:APP_URL = $url
Write-Host ""; Write-Host "URL para o telefone:  $url" -ForegroundColor Green
Write-Host "No computador: $url/equipe  -> Configuracoes > Impressao. Mantenha esta janela aberta. Ctrl+C para parar." -ForegroundColor Green
try { php -S "127.0.0.1:$Port" -t public public\index.php } finally { Stop-Process -Id $tun.Id -Force -ErrorAction SilentlyContinue }
