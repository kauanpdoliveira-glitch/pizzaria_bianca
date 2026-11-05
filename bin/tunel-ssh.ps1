# Tunel ALTERNATIVO por SSH (localhost.run) para a homologacao, usado quando o tunel rapido da Cloudflare esta indisponivel (ex.: HTTP 429).
# Autorizado pelo proprietario em 04/11/2026. E um servico de TERCEIROS: o endereco (*.lhr.life) e sorteado e MUDA a cada reconexao;
# sem conta nao ha endereco fixo. Expoe apenas a porta do site (127.0.0.1:8080); nunca o QZ, o banco ou o painel interno alem do que o site ja serve.
# Uso: powershell -ExecutionPolicy Bypass -File bin\tunel-ssh.ps1 [start|stop|url]
param([Parameter(Position = 0)][string]$Action = 'start', [switch]$Run, [int]$Port = 8080)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot; $state = Join-Path $root 'storage\homologacao'; $logs = Join-Path $state 'logs'
New-Item -ItemType Directory -Force $state, $logs | Out-Null
$urlFile = Join-Path $state 'url.txt'; $pidFile = Join-Path $state 'tunel-ssh.pid'; $envFile = Join-Path $state 'env.ps1'
function Log($m) { Add-Content (Join-Path $logs 'tunel-ssh.log') ("[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $m) }

if (-not $Run) {
    switch ($Action) {
        'url' { Write-Host (Get-Content $urlFile -ErrorAction SilentlyContinue); break }
        'stop' {
            if (Test-Path $pidFile) { Stop-Process -Id (Get-Content $pidFile) -Force -ErrorAction SilentlyContinue; Remove-Item $pidFile }
            Get-CimInstance Win32_Process -Filter "Name='ssh.exe'" | Where-Object { $_.CommandLine -match 'localhost\.run' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
            if ((Get-Content $urlFile -ErrorAction SilentlyContinue) -match 'lhr\.life') { Remove-Item $urlFile -ErrorAction SilentlyContinue }; Write-Host 'Tunel SSH parado.'; break
        }
        default {
            if (Test-Path $pidFile) { $op = Get-Content $pidFile; if (Get-Process -Id $op -ErrorAction SilentlyContinue) { Write-Host "Ja esta rodando. URL: $(Get-Content $urlFile -ErrorAction SilentlyContinue)"; exit 0 } }
            Start-Process powershell -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $MyInvocation.MyCommand.Path, 'start', '-Run', '-Port', $Port) -WindowStyle Hidden
            for ($i = 0; $i -lt 60 -and -not (Test-Path $urlFile); $i++) { Start-Sleep 1 }
            Write-Host ("URL: " + (Get-Content $urlFile -ErrorAction SilentlyContinue))
        }
    }
    exit 0
}

# --- processo vigia (segundo plano): reconecta se o SSH cair, com espera crescente (sem rajadas) ---
Set-Content $pidFile $PID; Log "vigia iniciado (pid $PID)"; $wait = 15
while ($true) {
    $out = Join-Path $logs 'tunel-ssh.out'; Remove-Item $out -ErrorAction SilentlyContinue
    $p = Start-Process ssh -ArgumentList @('-o', 'StrictHostKeyChecking=accept-new', '-o', 'ServerAliveInterval=30', '-o', 'ServerAliveCountMax=3', '-o', 'ExitOnForwardFailure=yes', '-R', "80:127.0.0.1:${Port}", 'nokey@localhost.run') -PassThru -WindowStyle Hidden -RedirectStandardOutput $out -RedirectStandardError (Join-Path $logs 'tunel-ssh.err')
    $url = $null
    for ($i = 0; $i -lt 40 -and -not $url -and -not $p.HasExited; $i++) {
        Start-Sleep 1
        if (Test-Path $out) { $t = [regex]::Replace((Get-Content $out -Raw -ErrorAction SilentlyContinue), "\x1B\[[0-9;]*m", ''); $m = [regex]::Match($t, 'https://[a-z0-9]+\.lhr\.life'); if ($m.Success) { $url = $m.Value } }
    }
    if ($url) {
        Set-Content $urlFile $url; Log "tunel ativo: $url"; $wait = 15
        # APP_URL do site passa a ser o endereco ativo (links de convite/recuperacao e cookie Secure); o supervisor reinicia o app com ele
        Set-Content $envFile ('$env:APP_URL = ''' + $url + '''')
        Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object { $_.CommandLine -match "127.0.0.1:$Port" } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
        $p.WaitForExit(); Log 'ssh caiu: reconectando (a URL vai mudar; reenvie convites)'; if ((Get-Content $urlFile -ErrorAction SilentlyContinue) -match 'lhr\.life') { Remove-Item $urlFile -ErrorAction SilentlyContinue }
    } else { if (-not $p.HasExited) { Stop-Process -Id $p.Id -Force }; Log "sem URL (nova tentativa em ${wait}s)"; $wait = [Math]::Min(300, $wait * 2) }
    Start-Sleep $wait
}
