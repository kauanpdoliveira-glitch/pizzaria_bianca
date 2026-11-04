# Homologacao da Bianca: app PHP + tunel Cloudflare com supervisor (reinicia se cair), logs com rotacao e verificacao de saude.
# Uso:  powershell -ExecutionPolicy Bypass -File bin\homologacao.ps1 <acao>
#   start    inicia o supervisor em segundo plano (app + tunel) e mostra a URL
#   stop     para supervisor, app e tunel
#   status   mostra processos, URL ativa e resposta de /saude
#   url      mostra a URL publica ativa
#   backup   faz um backup rotativo (14 copias) em D:\Pizzaria_Bianca-backups\homologacao
# Requisitos: PHP 8.2+ no PATH e cloudflared.exe (D:\Pizzaria_Bianca-ferramentas\cloudflared.exe).
# IMPORTANTE: o tunel rapido (trycloudflare.com) MUDA de endereco a cada reinicio do tunel; links de convite antigos deixam de apontar
# para o lugar certo (reenvie o convite pelo painel). O computador precisa ficar LIGADO, sem suspender, com internet.
# Credenciais de e-mail (SMTP) NUNCA ficam aqui: coloque em storage\homologacao\env.ps1 (ignorado pelo Git), ex.:
#   $env:MAIL_DRIVER='smtp'; $env:MAIL_HOST='...'; $env:MAIL_PORT='587'; $env:MAIL_USER='...'; $env:MAIL_PASS='...'; $env:MAIL_FROM='...'
param([Parameter(Position = 0)][string]$Action = 'status', [switch]$Run, [int]$Port = 8080)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$state = Join-Path $root 'storage\homologacao'; $logs = Join-Path $state 'logs'
New-Item -ItemType Directory -Force $state, $logs | Out-Null
$cf = Join-Path (Split-Path -Parent $root) 'Pizzaria_Bianca-ferramentas\cloudflared.exe'
$pidFile = Join-Path $state 'supervisor.pid'; $urlFile = Join-Path $state 'url.txt'

function Log($m) { $f = Join-Path $logs 'supervisor.log'; Add-Content -Path $f -Value ("[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $m) }
function Rotate($name) {   # rotacao simples: 5 MB, guarda 5 arquivos
    $f = Join-Path $logs $name
    if ((Test-Path $f) -and (Get-Item $f).Length -gt 5MB) {
        for ($i = 4; $i -ge 1; $i--) { $a = "$f.$i"; if (Test-Path $a) { Move-Item $a "$f.$($i + 1)" -Force } }
        Move-Item $f "$f.1" -Force
    }
}
function Health() { try { $r = Invoke-WebRequest "http://127.0.0.1:$Port/saude" -UseBasicParsing -TimeoutSec 5; return ($r.StatusCode -eq 200) } catch { return $false } }
function Running($name) { return @(Get-CimInstance Win32_Process -Filter "Name='$name'" | Where-Object { $_.CommandLine -match [regex]::Escape($root) -or $_.CommandLine -match "127.0.0.1:$Port" -or $_.CommandLine -match 'trycloudflare|--url' }) }

function EnvSetup($url) {
    $env:DB_DRIVER = 'sqlite'; $env:DB_SQLITE_PATH = Join-Path $root 'storage\teste-tunel.sqlite'
    $env:APP_ENV = 'staging'; $env:PRINT_ENABLED = '1'
    if (-not $env:MAIL_DRIVER) { $env:MAIL_DRIVER = 'log' }
    $extra = Join-Path $state 'env.ps1'; if (Test-Path $extra) { . $extra }
    if ($url) { $env:APP_URL = $url }
}
function StartTunnel() {
    $tlog = Join-Path $logs 'tunnel.log'; Rotate 'tunnel.log'; Remove-Item (Join-Path $logs 'tunnel.cur') -ErrorAction SilentlyContinue
    $cur = Join-Path $logs 'tunnel.cur'
    $p = Start-Process -FilePath $cf -ArgumentList @('tunnel', '--no-autoupdate', '--url', "http://127.0.0.1:$Port", '--logfile', $cur) -PassThru -WindowStyle Hidden
    $url = $null
    for ($i = 0; $i -lt 60 -and -not $url; $i++) { Start-Sleep 1; if (Test-Path $cur) { $m = Select-String -Path $cur -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' | Select-Object -First 1; if ($m) { $url = $m.Matches[0].Value } } }
    if (-not $url) { Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue; throw 'URL do tunel nao obtida' }
    Set-Content $urlFile $url; Log "tunel ativo: $url"; return @{ Proc = $p; Url = $url }
}
function StartApp($url) {
    EnvSetup $url
    Rotate 'app.log'
    $p = Start-Process php -ArgumentList @('-S', "127.0.0.1:$Port", '-t', 'public', 'public\index.php') -WorkingDirectory $root -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $logs 'app.log')
    Start-Sleep 2; Log "app PHP iniciado (pid $($p.Id))"; return $p
}

if (-not $Run) {   # o processo supervisor (-Run) NAO executa as acoes (evita recursao)
  switch ($Action) {
    'start' {
        if (Test-Path $pidFile) { $op = Get-Content $pidFile; if (Get-Process -Id $op -ErrorAction SilentlyContinue) { Write-Host "Ja esta rodando (supervisor $op). Use status."; exit 0 } }
        if (-not (Test-Path $cf)) { throw "cloudflared.exe nao encontrado em $cf" }
        php bin\migrate.php | Out-Null   # migracoes versionadas (seguras: so aplicam o que falta)
        Start-Process powershell -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $MyInvocation.MyCommand.Path, 'start', '-Run', '-Port', $Port) -WindowStyle Hidden
        for ($i = 0; $i -lt 90 -and -not (Test-Path $urlFile); $i++) { Start-Sleep 1 }
        Start-Sleep 3; Write-Host ("URL: " + (Get-Content $urlFile -ErrorAction SilentlyContinue)); Write-Host ("Saude: " + (Health)); break
    }
    'stop' {
        if (Test-Path $pidFile) { Stop-Process -Id (Get-Content $pidFile) -Force -ErrorAction SilentlyContinue; Remove-Item $pidFile }
        Get-CimInstance Win32_Process -Filter "Name='cloudflared.exe'" | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
        Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object { $_.CommandLine -match "127.0.0.1:$Port" } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
        Remove-Item $urlFile -ErrorAction SilentlyContinue; Log 'parado'; Write-Host 'Parado.'; break
    }
    'url' { Write-Host (Get-Content $urlFile -ErrorAction SilentlyContinue); break }
    'status' {
        $sup = (Test-Path $pidFile) -and (Get-Process -Id (Get-Content $pidFile) -ErrorAction SilentlyContinue)
        Write-Host ("Supervisor: " + $(if ($sup) { 'ativo' } else { 'parado' })); Write-Host ("App (PHP) em 127.0.0.1:${Port}: " + $(if (Health) { 'saudavel' } else { 'SEM RESPOSTA' }))
        Write-Host ("Tunel cloudflared: " + $(if (@(Get-Process cloudflared -ErrorAction SilentlyContinue).Count) { 'ativo' } else { 'parado' })); Write-Host ("URL: " + (Get-Content $urlFile -ErrorAction SilentlyContinue)); break
    }
    'backup' {
        $dest = 'D:\Pizzaria_Bianca-backups\homologacao'; New-Item -ItemType Directory -Force $dest | Out-Null
        EnvSetup $null; php (Join-Path $root 'bin\backup.php') $dest | Out-Null
        $new = Get-ChildItem $dest -Filter 'bianca-*.json' | Sort-Object LastWriteTime -Descending | Select-Object -First 1
        (Get-FileHash $new.FullName -Algorithm SHA256).Hash.ToLower() + '  ' + $new.Name | Set-Content ($new.FullName + '.sha256')
        Get-ChildItem $dest -Filter 'bianca-*.json' | Sort-Object LastWriteTime -Descending | Select-Object -Skip 14 | ForEach-Object { Remove-Item $_.FullName, ($_.FullName + '.sha256') -Force -ErrorAction SilentlyContinue }
        Write-Host "Backup: $($new.FullName) ($([int]($new.Length / 1KB)) KB); retencao: 14 copias."; break
    }
    default { Write-Host 'Acoes: start | stop | status | url | backup' }
}
}

if ($Action -eq 'start' -and $Run) {   # processo supervisor (fica em segundo plano)
    Set-Content $pidFile $PID; Log "supervisor iniciado (pid $PID)"
    $t = $null; $app = StartApp $null; $fails = 0; $nextTunnel = Get-Date   # o site local sobe SEMPRE; o tunel e tentado com espera crescente
    $wait = 60
    while ($true) {
        Start-Sleep 20
        try {
            if ($t -and $t.Proc.HasExited) { Log 'tunel caiu (a URL mudara; reenvie convites)'; $t = $null; Remove-Item $urlFile -ErrorAction SilentlyContinue; $nextTunnel = Get-Date }
            if (-not $t -and (Get-Date) -ge $nextTunnel) {
                try { $t = StartTunnel; $wait = 60; if (-not $app.HasExited) { Stop-Process -Id $app.Id -Force }; $app = StartApp $t.Url; $fails = 0 }
                catch { Log ("tunel indisponivel: " + $_.Exception.Message + " (nova tentativa em ${wait}s)"); $nextTunnel = (Get-Date).AddSeconds($wait); $wait = [Math]::Min(900, $wait * 2) }
            }
            if ($app.HasExited -or -not (Health)) { $fails++ } else { $fails = 0 }
            if ($fails -ge 2) { Log 'app sem resposta: reiniciando'; if (-not $app.HasExited) { Stop-Process -Id $app.Id -Force }; $app = StartApp $(if ($t) { $t.Url } else { $null }); $fails = 0 }
            Rotate 'supervisor.log'
        } catch { Log ("erro no supervisor: " + $_.Exception.Message) }
    }
}