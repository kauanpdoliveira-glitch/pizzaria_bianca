# Verifica a SINTAXE de todos os .js do painel/site no navegador (não há Node neste ambiente). Sobe uma página estática temporária
# (sem a CSP do app, que proíbe eval) que baixa cada arquivo e faz new Function(código) só para analisar — nada é executado.
#   powershell -ExecutionPolicy Bypass -File bin\verificar-js.ps1    -> abra http://127.0.0.1:8098/ e leia "ok"/"ERRO" por arquivo
$root = Split-Path -Parent $PSScriptRoot
$tmp = Join-Path $env:TEMP 'bianca-jschk'; New-Item -ItemType Directory -Force $tmp | Out-Null; Remove-Item (Join-Path $tmp '*') -Force -ErrorAction SilentlyContinue
Copy-Item (Join-Path $root 'public\assets\*.js') $tmp -Force; Copy-Item (Join-Path $root 'public\assets\dist\*.js') $tmp -Force
$files = (Get-ChildItem (Join-Path $tmp '*.js') | ForEach-Object { "'" + $_.Name + "'" }) -join ','
$html = "<!doctype html><meta charset=utf-8><pre id=o>...</pre><script>(async()=>{const files=[$files];const out=[];for(const f of files){const t=await (await fetch(f)).text();try{new Function(t);out.push('ok   '+f)}catch(e){out.push('ERRO '+f+' -> '+e.message)}}document.getElementById('o').textContent=out.join('\n');document.title='done'})();</script>"
[IO.File]::WriteAllText((Join-Path $tmp 'index.html'), $html, (New-Object Text.UTF8Encoding $false))
Write-Host 'Servindo em http://127.0.0.1:8098/ (Ctrl+C para parar)'; php -S 127.0.0.1:8098 -t $tmp
