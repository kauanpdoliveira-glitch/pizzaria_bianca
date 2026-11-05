# Copia um SEGREDO para a area de transferencia por 90 segundos (depois limpa), para VOCE colar no campo do painel da Vercel.
# Nada e impresso na tela. Nao use em computador compartilhado.
#   powershell -ExecutionPolicy Bypass -File bin\copiar-segredo.ps1 banco     -> senha do banco Postgres (guardada por supabase-credenciais.ps1)
#   powershell -ExecutionPolicy Bypass -File bin\copiar-segredo.ps1 qzchave   -> chave privada da assinatura QZ (storage\qz\private-key.pem), com \n literais
param([Parameter(Mandatory = $true)][ValidateSet('banco', 'qzchave')][string]$Que)
$root = Split-Path -Parent $PSScriptRoot
if ($Que -eq 'banco') {
    $pf = Join-Path $root 'storage\homologacao\supabase-pass.dpapi'
    if (-not (Test-Path $pf)) { Write-Host 'Senha ainda nao guardada (bin\supabase-credenciais.ps1).'; exit 2 }
    $v = [Net.NetworkCredential]::new('', (ConvertTo-SecureString (Get-Content $pf))).Password
} else {
    $kf = Join-Path $root 'storage\qz\private-key.pem'
    if (-not (Test-Path $kf)) { Write-Host 'Chave QZ nao encontrada (php bin\qz-trust-bundle.php).'; exit 2 }
    $v = ((Get-Content $kf -Raw).Trim() -replace "`r?`n", '\n')
}
Set-Clipboard -Value $v
Write-Host 'Copiado. Cole no campo Value da Vercel AGORA (a area de transferencia sera limpa em 90 segundos).'
Start-Sleep 90
if ((Get-Clipboard -Raw) -eq $v) { Set-Clipboard -Value ' ' }
Write-Host 'Area de transferencia limpa.'
