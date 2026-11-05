# Guarda a SENHA DO BANCO (Postgres) do Supabase de forma protegida, SEM aparecer na conversa, em log ou no Git.
# Execute VOCE MESMO, em um PowerShell seu:   powershell -ExecutionPolicy Bypass -File bin\supabase-credenciais.ps1
# A senha e criptografada com o Windows (DPAPI) e so este usuario deste computador consegue ler.
# Arquivo: storage\homologacao\supabase-pass.dpapi  (ignorado pelo Git).
# ATENCAO: nao e a chave "anon/publishable" nem a "service_role" da API: e a senha do BANCO (Database password), definida ao criar o projeto
# (se nao lembrar: Supabase > Project Settings > Database > Reset database password; isso derruba conexoes antigas).
$root = Split-Path -Parent $PSScriptRoot
$dir = Join-Path $root 'storage\homologacao'; New-Item -ItemType Directory -Force $dir | Out-Null
$sec = Read-Host 'Senha do banco Postgres do Supabase (nao aparece na tela)' -AsSecureString
if ($sec.Length -lt 8) { Write-Host 'Senha muito curta; nada foi gravado.'; exit 1 }
$sec | ConvertFrom-SecureString | Set-Content (Join-Path $dir 'supabase-pass.dpapi')
Write-Host 'Senha guardada (criptografada para o seu usuario do Windows). Pode fechar esta janela.'
