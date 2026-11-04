# Homologação — testes operacionais de várias semanas

Ambiente identificado como **“HOMOLOGAÇÃO — testes da Bianca”** (`APP_ENV=staging`): faixa visível em todas as páginas, `noindex` (cabeçalho, meta e robots) e toda comanda impressa com **TESTE — NÃO PRODUZIR**. `noindex` **não** substitui autenticação: o cardápio é público de propósito (clientes de teste pelo telefone); a gestão exige login por perfil. Pagamentos online e mensagens a clientes **não existem/estão desligados** (e-mail em `MAIL_DRIVER=log`). Impressão física só com `PRINT_ENABLED=1` e uma estação aberta e iniciada.

## O que precisa persistir (e onde)
| Dado | Onde fica | Observação |
|---|---|---|
| Pedidos, clientes, mesas, comandas, caixa, usuários, permissões | Banco (MySQL em hospedagem; SQLite só no PC) | migrações versionadas em `migrations/` (`php bin/migrate.php`) |
| Catálogo, configurações, **destinos/vias**, **impressoras e estações**, **layouts e versões**, fila de impressão | Banco | entram em `bin/backup.php` |
| Sessões | Banco (`SESSION_DRIVER=db`) ou arquivo | não entram no backup |
| Imagens enviadas | Banco (`UPLOAD_DRIVER=db`) ou `public/uploads/` | `uploaded_files` entra no backup (base64) |
| Chaves QZ | `storage/qz/` ou variáveis `QZ_*` **do servidor** | **nunca** no Git nem no backup público |

## Backup e restauração (testado: 556 linhas ida e volta; imagem binária preservada)
```bash
php bin/backup.php /caminho/seguro          # JSON (dados pessoais: acesso restrito, ver docs/BACKUP-LGPD.md)
php bin/restore.php arquivo.json            # banco vazio
php bin/restore.php arquivo.json --force    # SUBSTITUI o conteúdo (peça só depois de conferir)
```
Rotina sugerida para as semanas de teste: backup diário e antes de cada implantação; guardar as últimas 14 cópias fora do servidor; em MySQL, complemente com `mysqldump` do provedor. **Teste a restauração** em um banco novo antes de depender dela.

## Logs
`app_log` grava só eventos técnicos (ex.: “order #12 created”); não grava nome, telefone, endereço nem conteúdo de comandas. Falhas de impressão guardam apenas a mensagem do QZ/spooler no trabalho.

## Migração do túnel (controle)
1. Pausar a estação do túnel (**Parar processamento**); manter os trabalhos retidos.
2. Decidir: banco novo (restaurar o backup) ou o mesmo. Nunca dois consumidores na mesma fila: cada ambiente tem seu banco e sua fila.
3. Cadastrar a estação no novo ambiente, abrir a página dela **na nova URL**, conectar ao QZ (o QZ trata a nova origem como um novo site: aprovar uma vez).
4. Só encerrar o túnel depois de validar o fluxo na nova URL.
