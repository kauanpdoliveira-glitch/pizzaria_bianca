# Ambiente de testes na Vercel — o que existe, o que falta

**Estado: NÃO publicado.** A Vercel não executa PHP nativamente, o disco é efêmero e o login na Vercel só pode ser feito pelo próprio dono da conta (o navegador do Claude está deslogado). O repositório já traz o necessário do lado do código; faltam decisões e contas externas listadas abaixo.

## Arquitetura escolhida (a confirmar)
**Tudo na Vercel com o runtime PHP da comunidade** ([vercel-community/php](https://github.com/vercel-community/php), PHP 8.3 = `vercel-php@0.7.x`) + **MySQL externo** + imagens e sessões no banco. Motivo: o site é renderizado em PHP (views); separar um "frontend na Vercel" exigiria reescrever a interface, o que não é o objetivo. A alternativa sem risco de runtime não oficial é hospedar o app PHP em Hostinger/HostMia (`docs/DEPLOY-HOSTMIA.md`) e usar a Vercel só como proxy — sem ganho real.

Runtime da comunidade **não é suporte oficial da Vercel**: pode mudar ou quebrar. Por isso o ambiente é só de **testes**.

## O que já está no código
| Necessidade | Solução no repositório | Verificado |
|---|---|---|
| Entrada serverless | `api/index.php` + `vercel.json` (`vercel-php@0.7.4`, rota única) | **Não** (nunca implantado) |
| Banco persistente | MySQL externo via `DB_DRIVER=mysql` + `DB_*` | **Não** (MySQL nunca executado; migrações escritas para os dois bancos) |
| Sessões compartilhadas | `SESSION_DRIVER=db` (tabela `app_sessions`) | Sim, em SQLite: testes + e2e HTTP (4 sessões gravadas) |
| Imagens duráveis | `UPLOAD_DRIVER=db` (tabela `uploaded_files`, servida por `/uploads/…`) | Sim, em SQLite (gravar/ler/nome inválido) |
| Segredos só no servidor | variáveis de ambiente (`DB_*`, `QZ_*`); `.env` fora do Git | varredura de segredos OK |
| Separação teste/produção | `APP_ENV=staging`: faixa "AMBIENTE DE TESTES", `noindex` (cabeçalho, meta, robots), **impressão física desligada** (`PRINT_ENABLED` ≠ 1) | Sim (testes unitários) |
| Pagamentos e avisos reais | O sistema **não** processa pagamento online nem envia WhatsApp; e-mail só com `MAIL_DRIVER=mail|smtp` (deixe `log`) | por inspeção do código |

## Variáveis de ambiente (Vercel → Project → Settings → Environment Variables)
`APP_ENV=staging` · `APP_URL=https://<projeto>.vercel.app` · `DB_DRIVER=mysql` · `DB_HOST` `DB_PORT` `DB_NAME` `DB_USER` `DB_PASS` (do MySQL externo) · `SESSION_DRIVER=db` · `UPLOAD_DRIVER=db` · `MAIL_DRIVER=log`. **Não** defina `PRINT_ENABLED`. Cookies de sessão são `Secure; HttpOnly; SameSite=Lax` (a Vercel serve HTTPS). Não há CORS: o app é de mesma origem.

## O que depende do dono (nada disso pode ser feito por mim)
1. **Login na Vercel** pelo fluxo oficial (`vercel login` / navegador) — eu não peço senha, token nem código.
2. **Um MySQL externo gratuito** (conta própria; ex.: planos gratuitos de provedores de MySQL/MariaDB/TiDB com TLS). Custo/plano é decisão do dono; **nada foi contratado**.
3. Importar o banco: `php bin/migrate.php` e `php bin/restore.php database/export/catalogo-2026-11-04.json`, depois `php bin/create-admin.php` apontando `DB_*` para esse MySQL (rodado de um computador com PHP; o catálogo é **real**, os pedidos serão de teste).
4. Conferir se o runtime da comunidade serve `public/` como estático com `vercel.json` atual (não confirmado).

## Roteiro quando as contas existirem
`vercel link` → configurar variáveis → `vercel deploy` (prévia, não produção) → rodar o roteiro de 11 passos (vitrine, pedido, painel, sessão, permissões, upload/leitura de imagem, `X-Robots-Tag`, cabeçalhos/gzip reais, console/rede, desempenho com 4G simulado) **na URL real** e registrar em `docs/`. Nenhuma dessas verificações foi feita ainda.

## Limites a conhecer
Tempo/memória por função e *cold start* do runtime PHP; MySQL externo adiciona latência; imagens no banco servem para um cardápio pequeno (≤ 1200 px, JPEG), não para milhares de fotos; sem Brotli/gzip garantidos até medir na URL real (a Vercel costuma comprimir respostas de função, **a confirmar**).
