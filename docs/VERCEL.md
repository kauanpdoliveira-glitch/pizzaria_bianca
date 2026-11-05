# Ambiente de testes na Vercel — o que existe, o que falta

**Estado: NÃO publicado (bloqueio de elegibilidade abaixo).** A Vercel não executa PHP nativamente, o disco é efêmero e o login na Vercel só pode ser feito pelo próprio dono da conta (o navegador do Claude está deslogado). O repositório já traz o necessário do lado do código; faltam decisões e contas externas listadas abaixo.

## Bloqueio de elegibilidade (verificado em 04/11/2026)
A página oficial do plano **Hobby** ([vercel.com/docs/plans/hobby](https://vercel.com/docs/plans/hobby)) diz que o plano gratuito é para projetos pessoais e que, conforme as *fair use guidelines*, **restringe o uso a fins não comerciais e pessoais**. A Bianca Pizzaria é um negócio: chamar o ambiente de “teste” **não** o torna elegível por si só. Publicar nele pode violar os termos e arriscar suspensão. Eu **não** presumo que seja permitido, e não publiquei.

Além disso: (a) o PHP só roda pelo runtime **da comunidade** (não oficial); (b) falta um **MySQL externo** — por exemplo o plano gratuito *Starter* do TiDB Cloud (5 GiB por instância segundo a documentação) —, cujas **condições de uso comercial não encontrei** e precisam ser lidas por você; (c) login e criação de contas só você pode fazer.

### Alternativas (a decisão é sua)
| Opção | Custo | Prós | Contras |
|---|---|---|---|
| **A. Teste dentro do próprio computador + túnel temporário** (o app PHP e o QZ no mesmo PC; um túnel gratuito dá ao telefone uma URL pública) | R$ 0 | Atende o teste doméstico hoje; sem hospedar; dados só no PC | Precisa de PHP no PC e de baixar uma ferramenta de túnel (peço sua autorização antes); URL some ao fechar; **não** é a Vercel |
| **B. Vercel Pro (teste gratuito de 14 dias ou pago)** | trial ou US$ 20/usuário/mês | Uso comercial permitido; é o destino desejado | Runtime PHP não oficial; ainda precisa de MySQL externo; trial tem limites |
| **C. Hostinger/HostMia (PHP + MySQL nativos)** | plano pago da hospedagem | Roda o app como foi feito; menor alteração | Já era a fase “depois”; custo |
| **D. Vercel Hobby assim mesmo** | R$ 0 | — | **Contraria os termos** para negócio; não recomendo |

Recomendação de menor alteração e custo para **o teste de casa**: **A**; para hospedagem de testes “de verdade” com a Vercel: **B** (começando pelo teste gratuito), sempre com MySQL externo.
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

## Confirmado na conta (04/11/2026)
Conta aberta no navegador: time `kauanpdoliveira-7733`, **plano Hobby ativo, sem cartão cadastrado**. Pela definição oficial de uso comercial (fair use guidelines: qualquer deployment usado para ganho financeiro de qualquer envolvido, incluindo *anunciar a venda de produto ou serviço* e *receber para criar/atualizar/hospedar o site*), um site de pizzaria que vende pelo cardápio digital exige **Pro ou Enterprise**. O Hobby **não** é uma opção elegível. Nenhum projeto foi criado nem alterado nessa conta.

## Segunda conta verificada (04/11/2026)
Conta/equipe `paingodev-7164`: **plano Hobby ativo**, nenhum projeto, nenhum projeto da Bianca. A troca de conta **não muda** a elegibilidade: o Hobby é restrito a uso não comercial e pessoal (fair use guidelines), e testes operacionais de uma pizzaria de várias semanas, com pedidos pelo site, são uso comercial. Por isso **nada foi criado nem publicado** nessa conta. Opção elegível na Vercel: **Pro** (US$ 20 por usuário por mês, com cartão e autorização do proprietário), que ainda exigiria MySQL externo e runtime PHP da comunidade. Alternativa sem Vercel: hospedagem PHP/MySQL (Hostinger/HostMia).

## Projeto importado na Vercel (verificado em 04/11/2026, 21:5x)
- **Conta/equipe:** `paingodev-7164` (plano **Hobby**). **Projeto:** `pizzaria_bianca` (`prj_A8cDIgSqG9f2TlnJSUEfcATATFD0`). **Domínio atribuído:** `pizzariabianca.vercel.app` (`pizzaria-bianca` não foi tentado por já haver domínio válido).
- **Git:** `kauanpdoliveira-glitch/pizzaria_bianca`, branch `main` → ambiente *Production*. **Build:** *Framework Preset = Other*, sem comando de build/instalação, sem diretório de saída, raiz do repositório. **Variáveis de ambiente: nenhuma configurada.**
- **Deployments:** `16a089c` e `eb8ed37` ficaram *Ready* (14–17 s, sem build). *Ready* não significa funcionando.
- **Resultado real da URL (antes desta correção):** `/` devolvia o **código-fonte de `public/index.php`** (servido como arquivo estático, `application/x-httpd-php`); `/cardapio` e `/api/menu` → **500**, `/saude` → **503**: o runtime PHP da comunidade executa, mas **não há banco**, e SQLite em disco efêmero não serve. `/src`, `/.env.example`, `/vercel.json` → 404.
- **Correções:** `vercel.json` passou a rotear `/` e `/index.php` para a função (o arquivo não é mais servido como texto) e a página de erro não depende do banco; sem `DB_DRIVER=mysql` no ambiente Vercel o site responde **503 honesto** ("banco externo ainda não configurado") em vez de erro em branco.
- **O que falta para funcionar (decisão e serviço externos):** MySQL externo (conta do proprietário) e as variáveis `APP_ENV=staging`, `APP_URL=https://pizzariabianca.vercel.app`, `DB_DRIVER=mysql`, `DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS`, `SESSION_DRIVER=db`, `UPLOAD_DRIVER=db`, `MAIL_DRIVER`/SMTP. Depois: `php bin/migrate.php` e `php bin/restore.php` apontando para esse banco (uma vez, fora do build). **Elegibilidade:** o plano Hobby segue restrito a uso não comercial (fair use) e o runtime PHP é da comunidade; a estação de impressão continua no computador local (a Vercel não alcança a impressora).
