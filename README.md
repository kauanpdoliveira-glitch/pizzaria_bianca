# Bianca Pizzaria — site, cardápio digital, painel e salão

Pizzaria Bianca (Contagem/MG, desde 1977): site institucional, cardápio com montagem de pizza e checkout **sem cadastro do cliente**, acompanhamento do pedido, gestor de pedidos, catálogo administrativo, Portal da Equipe (proprietário, gerente/caixa, garçom), mesas/comandas/caixa e fila de impressão de comandas.

> **Estado:** pronto para homologação. **Não está pronto para produção**: impressão física, MySQL de produção, e-mail real e a hospedagem não foram verificados (veja "Pendências externas").

## Tecnologias e requisitos
| Item | Detalhe |
|---|---|
| Linguagem | **PHP 8.2+** (validado em 8.3.36), sem framework e **sem Composer/npm** |
| Extensões | `pdo_sqlite` (dev) ou `pdo_mysql` (produção), `mbstring`, `gd`, `fileinfo`, `openssl`, `json` |
| Banco | **SQLite** (desenvolvimento e testes) · **MySQL/MariaDB** (produção; migrações escritas em SQL portátil, **nunca executadas em MySQL real**) |
| Front-end | HTML renderizado no servidor (`views/`), CSS/JS próprios, sem bibliotecas; CSP `script-src 'self'` |
| Build | `php bin/build-assets.php` (minificação + CSS da loja separado), `php bin/build-fonts.php` (WOFF), `php bin/build-images.php` |
| Testes | `php tests/run.php` (66, SQLite em memória) · `tests/e2e/` (Chrome headless, perfis, concorrência, desempenho) |

## Rodar localmente
```bash
cp .env.example .env                 # ajuste: APP_ENV=local, APP_URL=http://127.0.0.1:8080, DB_DRIVER=sqlite, DB_SQLITE_PATH=storage/bianca.sqlite
php bin/migrate.php                  # cria/atualiza o banco (migrações versionadas em migrations/)
php bin/restore.php database/export/catalogo-2026-11-04.json   # catálogo real (ou: php database/seed_menu.php = cardápio do PDF)
php bin/create-admin.php email@dominio "Nome"                  # 1º proprietário (senha digitada no terminal)
php bin/check.php                    # diagnóstico do ambiente: deve terminar em "Ambiente OK."
php -S 127.0.0.1:8080 -t public public/index.php               # site /  ·  equipe /equipe  ·  garçom /garcom
php tests/run.php                    # testes
```
`database/seed_demo.php` cria dados **fictícios** só para desenvolvimento (recusa `APP_ENV=production`; senha aleatória exibida uma única vez).

## Variáveis de ambiente (`.env`; nunca versionado)
| Variável | Uso |
|---|---|
| `APP_ENV` | `local` ou `production`. Em produção os assets minificados (`public/assets/dist/`) são usados |
| `APP_URL` | URL pública (https em produção; cookies `Secure` sob https) |
| `APP_TZ` | fuso (padrão `America/Sao_Paulo`) |
| `DB_DRIVER` | `mysql` (produção) ou `sqlite` |
| `DB_HOST` `DB_PORT` `DB_NAME` `DB_USER` `DB_PASS` | MySQL |
| `DB_SQLITE_PATH` | arquivo SQLite (dev) |
| `MAIL_DRIVER` `MAIL_FROM` `MAIL_*` | recuperação de senha: `log` (grava em `storage/mail/`, não envia), `mail` ou `smtp` (não testados com servidor real) |
| `ASSETS_MIN` | `1`/`0` força usar/não usar `dist/` |
Variáveis de ambiente do processo têm prioridade sobre o `.env`.

## Banco, migrações e dados iniciais
- Migrações em `migrations/NNN_*.sql` (tokens `{{PK}}`/`{{ENGINE}}` para SQLite/MySQL), aplicadas por `php bin/migrate.php` e registradas em `schema_migrations`. **MySQL:** DDL não é transacional — faça backup e rode primeiro num banco vazio (`docs/DEPLOY-HOSTMIA.md` descreve o roteiro; vale para qualquer hospedagem PHP+MySQL).
- Dados iniciais: `database/seed_menu.php` (cardápio transcrito do PDF) ou o **catálogo exportado** sem dados pessoais (`php bin/export-catalog.php` / `php bin/restore.php`).
- **Não há usuário padrão.** Nenhum seed contém senha, hash, token ou sessão.

## Primeiro proprietário (procedimento seguro)
`php bin/create-admin.php email "Nome"` (terminal; senha mín. 10 caracteres, letras e números; nunca em argumento de linha de comando, arquivo ou chat). Depois, o proprietário cadastra gerentes e garçons em **Usuários e permissões**. Sem SSH, veja o script temporário de uma execução em `docs/DEPLOY-HOSTMIA.md` (apague-o em seguida).

## Persistência de arquivos e uploads
- `public/assets/**` e `public/uploads/ifood/**` fazem parte do código (assets autorizados). Fotos enviadas pelo painel vão para `public/uploads/` e **precisam de disco persistente e backup próprio** — não estão no Git. Detalhes: `docs/ARQUIVOS-E-UPLOADS.md`.
- Em hospedagens efêmeras (serverless) o disco é descartado: não use uploads locais lá.

## Desempenho
Minificação (build) **não** é compressão HTTP: gzip/Brotli devem ser entregues pelo servidor (`public/.htaccess` traz as regras para Apache/LiteSpeed; verifique os cabeçalhos no servidor real). Medições e método: `docs/DESEMPENHO.md`.

## Documentação
`docs/HOSPEDAGEM.md` (Hostinger e Vercel) · `docs/DEPLOY-HOSTMIA.md` (roteiro PHP+MySQL, escrito para a HostMia — **outra hospedagem**) · `docs/GUIA-GERENTE.md` · `docs/IMPRESSAO.md` · `docs/BACKUP-LGPD.md` · `docs/EQUIPE-SALAO.md` · `docs/CATALOGO.md` · `docs/IFOOD.md` · `docs/MOVIMENTO.md` · `docs/VALIDACAO-2026-11-04.md` · `docs/VALIDACAO-2026-11-04-publicacao.md` · `PROGRESSO.md`.

## Estrutura
`public/` (docroot) · `src/` (regras) · `views/` · `migrations/` · `database/` (cargas e exportação do catálogo) · `bin/` (CLI: migrate, build, backup, restore, create-admin, check, import-ifood, scan-secrets, agente de impressão) · `tests/` · `storage/` (sqlite, logs, backups: **fora do docroot e fora do Git**) · `docs/`.

## Permissões
Garçom: salão (`/garcom`). Gerente/caixa: pedidos, salão, caixa, cardápio, clientes, vendas, impressão (conforme permissões). Proprietário: tudo + usuários, permissões, auditoria, configurações e painel executivo. Matriz em `src/Auth.php`; fora da matriz, é negado.

## Pendências externas (não verificadas)
Impressão física (a integração futura é a solução informada como "QZ3" — **identificação a confirmar**; ver `docs/IMPRESSAO.md`), MySQL/MariaDB real, e-mail real (`MAIL_DRIVER=mail|smtp`), hospedagem (Hostinger) e Vercel, teclado virtual/toque/leitor de tela em aparelhos reais, concorrência entre aparelhos e redes reais.

## Segurança
Não commite `.env`, bancos, backups, credenciais ou fotos privadas. Antes de publicar: `php bin/scan-secrets.php`.
