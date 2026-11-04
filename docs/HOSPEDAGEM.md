# Hospedagem: Hostinger (destino) e Vercel (testes futuros)

> **Não confunda:** a **Hostinger** é a hospedagem futura informada pelo usuário. A **HostMia** aparece nos documentos antigos (`DEPLOY-HOSTMIA.md`) e **não** é a Hostinger. O roteiro daquele documento (PHP + MySQL, domínio apontando para `public/`) vale como base, mas **nada aqui foi verificado em nenhuma das duas**: não houve deploy, conexão ao banco nem teste de cabeçalhos em hospedagem real.

## O que o sistema exige de qualquer hospedagem
| Necessidade | Motivo |
|---|---|
| PHP ≥ 8.2 com `pdo_mysql`, `mbstring`, `gd`, `fileinfo`, `openssl`, `json` | `bin/check.php` confere |
| MySQL/MariaDB (utf8mb4) | migrações em `migrations/` (nunca executadas em MySQL real) |
| **Disco persistente e gravável** em `storage/` e `public/uploads/` | logs, e-mails de teste, fotos enviadas pelo painel |
| Domínio apontando para `public/` (ou `.htaccess` da raiz como rede de segurança) | `src/`, `.env`, `storage/` nunca públicos |
| HTTPS | cookies de sessão `Secure`, login da equipe |
| `.htaccess` (Apache/LiteSpeed) | regras de reescrita, compressão e cache em `public/.htaccess` |
| Saída HTTP(S) a partir da loja | o agente de impressão busca a fila (conexão de saída) |
| Cron/processos persistentes | **não são necessários** (atualização por polling) |

## Hostinger — checklist para confirmar no painel (hPanel) antes do deploy
Confirmar (não presumido): versão do PHP selecionável e extensões acima; criação de banco/usuário MySQL e a versão do servidor; como definir o diretório raiz do domínio como `public/` (ou pasta `public_html` recebendo o conteúdo de `public/` + `bianca-app` ao lado, como em `DEPLOY-HOSTMIA.md`); acesso SSH (senão, script temporário descrito lá); servidor web (Apache/LiteSpeed) respeitando `.htaccess`; **compressão realmente entregue** (`curl -sI -H "Accept-Encoding: br, gzip" https://DOMINIO/assets/dist/site.min.css`); limite de upload ≥ 4 MB; SSL gratuito; backups da própria plataforma (não substituem `php bin/backup.php` + cópia de `public/uploads/`).
Passos: `.env` de produção (`APP_ENV=production`, `DB_DRIVER=mysql`, credenciais, `APP_URL=https://…`) → `php bin/migrate.php` num banco **vazio** de teste → `php bin/check.php` → catálogo (`bin/restore.php database/export/…` ou `seed_menu.php`) → `php bin/create-admin.php` → ativar HTTPS no `.htaccess` → pedido de teste cancelado com motivo "teste".

## Vercel (fase de testes) — o que é e o que não é compatível
Não presumir. Pontos conhecidos do projeto que **não funcionam por padrão** numa plataforma serverless:
| Parte | Situação na Vercel | Alternativa/decisão necessária |
|---|---|---|
| **PHP** | A Vercel não executa PHP nativamente (existe runtime comunitário, **não avaliado aqui**) | decidir entre runtime PHP de terceiros, container/VPS ou reescrever a camada de servidor |
| **MySQL** | Não há banco incluso | MySQL gerenciado externo (conexão TLS), com latência e limites a medir |
| **Uploads em `public/uploads/`** | Disco efêmero: fotos somem | armazenamento de objetos (S3 compatível) + URLs; exige mudar o upload |
| **Sessões PHP** (arquivos) e `storage/` (logs, e-mails de teste, SQLite) | Sem disco persistente/compartilhado entre instâncias | sessões em banco/Redis; logs para stdout; sem SQLite |
| **Fila de impressão + agente** | Funciona só se a API estiver acessível e estável (token Bearer); o agente roda **na loja** | manter, apontando para a URL de teste |
| **Assets estáticos** (`public/assets`, `dist/`) | Compatíveis com CDN estática | `php bin/build-assets.php` antes de publicar |
| **Compressão** | A plataforma costuma comprimir estáticos; para respostas PHP depende do runtime | verificar por cabeçalhos |
Requisitos mínimos para uma etapa de teste na Vercel: definir o runtime PHP (ou alternativa), MySQL externo, armazenamento para uploads, sessões persistentes, variáveis de ambiente (`APP_ENV`, `APP_URL`, `DB_*`, `MAIL_*`) e um pedido de teste ponta a ponta. **Esta tarefa não fez deploy na Vercel nem na Hostinger.**

## Impressão (futura)
Pontos de integração existentes: fila `print_jobs` (por setor), API do agente (`/api/print/claim`, `/api/print/{uuid}/result`, token Bearer), tela **Impressão** do painel e o agente de referência `bin/print-agent.php` (ESC/POS). A solução que o usuário pretende integrar foi informada apenas como **"QZ3"**: **produto, versão e modo de operação a confirmar** antes da etapa de impressão (não foi presumido que seja QZ Tray ou outro). Nada foi conectado a impressoras reais.
