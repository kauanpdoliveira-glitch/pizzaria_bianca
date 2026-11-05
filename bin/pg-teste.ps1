# Roda a suite de testes da Bianca contra o PostgreSQL do Supabase em um ESQUEMA DESCARTAVEL (bianca_test_*), sem tocar em dados reais.
# Requer a senha guardada por bin\supabase-credenciais.ps1. Usa o pooler em modo "session" (porta 5432) para o search_path valer na sessao.
param([string]$HostName = 'aws-1-us-west-2.pooler.supabase.com', [string]$User = 'postgres.szoezlijjdfswzmxwuxz', [string]$Db = 'postgres')
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot; Set-Location $root
$pf = Join-Path $root 'storage\homologacao\supabase-pass.dpapi'
if (-not (Test-Path $pf)) { Write-Host 'Senha ainda nao guardada. Rode: powershell -ExecutionPolicy Bypass -File bin\supabase-credenciais.ps1'; exit 2 }
$sec = ConvertTo-SecureString (Get-Content $pf)
$env:DB_PASS = [Net.NetworkCredential]::new('', $sec).Password
$env:DB_DRIVER = 'pgsql'; $env:DB_HOST = $HostName; $env:DB_PORT = '5432'; $env:DB_USER = $User; $env:DB_NAME = $Db
$schema = 'bianca_test_' + (-join ((97..122) | Get-Random -Count 8 | ForEach-Object { [char]$_ }))
$php = @('-d', 'extension=pdo_pgsql', '-d', 'extension=pgsql')
try {
    php @php tests\pg\schema.php create $schema
    $env:DB_SCHEMA = $schema; $env:BIANCA_TEST_PG = '1'; $env:APP_ENV = 'testing'
    php @php tests\run.php
} finally {
    Remove-Item Env:DB_SCHEMA, Env:BIANCA_TEST_PG -ErrorAction SilentlyContinue
    php @php tests\pg\schema.php drop $schema
    Remove-Item Env:DB_PASS -ErrorAction SilentlyContinue
}
