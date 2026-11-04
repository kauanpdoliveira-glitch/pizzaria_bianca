# Validação para publicação — correções, concorrência, desempenho e repositório (04/11/2026)

Legenda: **Aprovado** · **Falhou→corrigido** · **Simulado** · **Não executado** · **Bloqueado**. Ambiente: Windows 10, PHP 8.3.36, SQLite, Chrome 156 headless. Nada foi publicado em hospedagem, nenhum deploy (Vercel/Hostinger), nenhuma impressora, cobrança ou mensagem real.

## 1. Estado inicial e pendências
- Projeto atual = último ZIP (14:03): 218 arquivos idênticos (só os documentos de backup diferiam). Cópia de recuperação anterior às mudanças feita no computador de origem. **Não havia Git**; o remoto `kauanpdoliveira-glitch/pizzaria_bianca` existe, é **público e vazio** (sem commits).
- Resolvíveis aqui: regressão de carregamento, concorrência, higiene do repositório, documentação. **Dependem de equipamento/serviço externo:** teclado virtual, toque real, leitor de tela, aparelhos/redes reais, MySQL real, Hostinger, Vercel, impressão ("QZ3" a confirmar). Roteiro: `docs/ROTEIRO-VALIDACAO-EXTERNA.md`.

## 2. Correções
| # | Problema (reproduzido) | Correção | Reteste |
|---|---|---|---|
| 1 | Home carregava CSS e JS da loja (~13 KB + ~35 KB sem uso), sem minificação, fonte de 86 KB e imagem principal de 233 KB | build de produção (`bin/build-assets.php`): minificação, CSS *core*/*shop*, `app.js` só nas páginas da loja, `motion.js` separado, WOFF (−48%), hero 640 px (−54%) | `docs/DESEMPENHO.md`; estilos computados idênticos (12/12) |
| 2 | **Corrida:** 6 envios simultâneos da mesma chave ao abrir mesa: 3 recebiam **409** espúrio em vez do resultado da 1ª tentativa | chave de idempotência reconferida **dentro** da transação (`Floor::open`) | S3 aprovado (6/6 ok) |
| 3 | **`database is locked`** (erro 500) em envios simultâneos da mesma rodada (`sendRound`) | `Db::txImmediate` em `sendRound`, `requestBill`, `Orders::create`, `Orders::editItems`, `Printing` (claim) + recheque da chave na transação | S3b e S3c aprovados |
| 4 | Uploads sem proteção contra execução de PHP | `public/uploads/.htaccess` | — |
| 5 | Exportação do catálogo trazia bairros/mesas/horários de desenvolvimento | só tabelas de catálogo + observações do iFood | restauração verificada |
| 6 | Scripts de teste continham e-mail pessoal | credenciais de teste lidas de `storage/dev-credentials.txt` ou `E2E_OWNER_*` | varredura de segredos OK |

## 3. Testes
| Verificação | Resultado |
|---|---|
| `php tests/run.php` | **66 aprovados, 0 falhas** |
| Perfis por HTTP (proprietário, gerente, garçom), no servidor com build minificado | **68/68 aprovados** (`tests/e2e/roles-http-resultado-2026-11-04.txt`) |
| **Concorrência real no banco** (processos PHP independentes, mesma cópia SQLite, barreira de tempo) | **10/10 aprovados** (`tests/e2e/concurrency-resultado-2026-11-04.txt`): mesma mesa por 2 garçons (1 vence, outro 409); mesmo nº de comanda física (1 vence); 6 envios da mesma chave (abrir mesa, rodada e checkout: 1 registro, todos recebem a mesma resposta); 2 gerentes no mesmo pedido (1 grava, outro 409); preparo × cancelar (1 vence, estado coerente); 2 caixas pagando o total (o 2º recusado, sem excesso); fechamento duplicado; **fechar o atendimento antigo enquanto abre outro**: antigo fecha, novo fica ativo, mesa continua ocupada |
| Build minificado × fonte | estilos computados idênticos em 12/12 combinações (6 rotas × 1440/390 px); 0 erros de JS; compra completa, movimento e painéis OK |
| Desempenho (CPU 4×, 1,6 Mbps, 3 execuções, mediana, frio/quente) | home melhorou (FCP −35%, LCP −41% sem compressão); cardápio: FCP melhor, **LCP instável e pior no cenário gzip frio** — ver `docs/DESEMPENHO.md` |
| Segredos (`bin/scan-secrets.php`) | arquivos e histórico: nenhum achado |
**Limites (não omitir):** "concorrência" aqui são **processos no mesmo computador e disco**, com SQLite (`BEGIN IMMEDIATE`); **não** equivale a aparelhos/redes reais nem ao isolamento do MySQL (`FOR UPDATE`). A varredura de acessibilidade/responsividade de 69 combinações (`docs/VALIDACAO-2026-11-04.md`) foi feita na versão de código-fonte; no build minificado confirmei equivalência de estilos e ausência de erros nas rotas citadas, **sem repetir a varredura completa**. Teclado virtual, toque em aparelho real e leitor de tela: **Não executados**.

## 4. MySQL
**Não executado.** Não há MySQL/MariaDB instalado nem de teste. Preparar uma instância local exigiria baixar e executar um binário de terceiros, o que o ambiente de trabalho não permite sem verificação de origem; não foi feito. Há revisão estática das migrações (`docs/DEPLOY-HOSTMIA.md`, seção MySQL) e um teste local, quando feito, **não comprovaria** a conectividade com a Hostinger (nem com a HostMia).

## 5. Banco, seeds e primeiro proprietário
Migrações versionadas (`migrations/001…005`), `bin/migrate.php`, catálogo reproduzível (`seed_menu.php` do PDF ou `catalogo-2026-11-04.json`), **nenhum usuário/senha/hash/sessão/token em seeds ou no Git** (verificado). O proprietário nasce só por `php bin/create-admin.php` (senha digitada). `seed_demo.php` é só desenvolvimento, com senha aleatória.

## 6. Publicação
Ver o resultado (URL e SHA do commit) na entrega e em `LEIA-ME-BACKUP.md` do ZIP final.
