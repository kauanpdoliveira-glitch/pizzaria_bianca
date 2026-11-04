# Verificações com navegador real (Chrome headless via DevTools Protocol)

Scripts PHP sem dependências (cliente WebSocket próprio em `cdp.php`). **Só contra ambiente de teste**: use uma *cópia* do banco e um servidor em outra porta; nunca contra produção.

## Preparar
```powershell
# 1) cópia do banco e servidor de teste
Copy-Item storage\bianca.sqlite $env:TEMP\test.sqlite
$env:DB_SQLITE_PATH="$env:TEMP\test.sqlite"; $env:APP_URL="http://127.0.0.1:8099"
php -S 127.0.0.1:8099 -t public public/index.php          # em outro terminal
# 2) Chrome headless com porta de depuração (perfil descartável)
& "C:\Program Files\Google\Chrome\Application\chrome.exe" --remote-debugging-port=9333 --user-data-dir=$env:TEMP\prof --headless=new about:blank
```
Variáveis de ambiente (como `DB_SQLITE_PATH`) têm prioridade sobre o `.env`; confira que o servidor de teste e os scripts apontam para a **cópia**. Ajuste `CHROME_PATH` se o Chrome estiver em outro lugar. Portas: app 8099 (atual) e 8098 (variante sem movimento, para comparação), Chrome 9333.

## Scripts
| Script | O que verifica |
|---|---|
| `roles-http.php` | Perfis proprietário/gerente/garçom por HTTP (login, bloqueios por API, cenário crítico da mesa, idempotência). Cria contas de teste pela rota oficial `/admin/usuarios`. Resultado de referência: `roles-http-resultado-2026-11-04.txt`. |
| `verify-motion.php` | Landing em 3 condições (preferência natural, `no-preference` e `reduce` emulados), falha do script, JS desativado, impressão, navegação repetida. |
| `perf.php` | Carregamento: cache frio/quente, 3 execuções (mediana), sem limite e rede lenta + CPU 4x. |
| `cdp.php` | Cliente CDP mínimo usado pelos demais. |

> Condição **natural** = o que o navegador/sistema informa (neste ambiente: *reduzir movimento* ativo no Windows). `no-preference` só pode ser **emulado** (`Emulation.setEmulatedMedia`).
