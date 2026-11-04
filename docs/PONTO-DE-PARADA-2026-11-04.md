# Ponto de parada — 04/11/2026 (noite)

Procedimento **pausado a pedido do proprietário**. Nada novo foi iniciado depois da pausa.

## Estado ao parar
| Item | Estado |
|---|---|
| Código | Commit `8f40c41` no GitHub (`kauanpdoliveira-glitch/pizzaria_bianca`); `bin/user-status.php` ainda **não commitado** |
| Conta do proprietário | `kauango.dev@gmail.com` **ativa** (rótulo Fundador, último acesso 20:00); convite usado e arquivo `.eml` apagado |
| Conta de teste `dono@teste.local` | **Desativada** (CLI; a proteção "último proprietário ativo" foi verificada) |
| App PHP (8080) e túnel Cloudflare | **PARADOS** (o `stop` do supervisor rodou; o `start` foi interrompido antes de subir). **Não há URL pública ativa** agora |
| Supervisor `bin\homologacao.ps1` | Parado; nunca ficou rodando ainda |
| QZ Tray | Reiniciado com `override.crt` copiado para `D:\QzTray\` (sem admin; pasta do usuário). **Silencioso NÃO verificado**: falta conectar a página da estação e ver se o QZ ainda pede *Allow*/“Lembrar” |
| Banco de teste | `storage\teste-tunel.sqlite` (migrações até 011); backup antes dos convites em `D:\Pizzaria_Bianca-backups\`; backup rotativo em `...\homologacao\` |
| Trabalhos de impressão antigos (pedido #1) | **Retidos** (3), intactos |
| Estação “PC de casa — teste” | Parada (desarmada); layout **Minimalista ativo** nos 3 destinos |
| Tarefas agendadas do Windows | **Nenhuma criada** (dependem de autorização) |
| Vercel | Nada criado (contas Hobby; ver `docs/VERCEL.md`) |

## Para retomar (ordem sugerida)
1. `powershell -ExecutionPolicy Bypass -File bin\homologacao.ps1 start` → sobe app + túnel supervisionados e mostra a **nova URL** (muda a cada reinício do túnel; convites antigos não valem).
2. Entrar no painel com a conta do proprietário (`http://127.0.0.1:8080/equipe`) e abrir a estação; conferir se o QZ pede confirmação (teste do modo silencioso) — se aparecer, marcar “Lembrar esta decisão” uma vez e testar 1 página (precisa da sua autorização para o lote).
3. Aceitação pela URL pública e persistência após reinício (pedido único, status, fila, layouts).
4. Decisões externas: SMTP real (Brevo/Resend; credenciais em `storage\homologacao\env.ps1`), domínio para túnel nomeado, novo repositório GitHub (login GitHub no navegador do app), tarefas agendadas (backup diário/início com o Windows).
5. Commitar `bin/user-status.php` e este arquivo (rodar `php bin/scan-secrets.php` **antes** de qualquer push; só enviar se passar).
