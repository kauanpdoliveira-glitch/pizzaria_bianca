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

## Acesso: proprietário, convites e conta da estação
- **Proprietário/fundador:** `php bin/provision-owner.php kauango.dev@gmail.com "Nome"` (no servidor; não há endpoint público). Cria a conta **pendente e inativa**, sem senha, com o rótulo "Fundador" (só rótulo: as permissões vêm do perfil `owner` no backend) e envia o convite. Recusa (CONFLITO) se a conta já existe e está ativa; se já houver outro proprietário ativo exige `--additional-owner` de propósito; reexecutar para a mesma conta **pendente** só reenvia o convite (invalida os anteriores).
- **Convite:** token de 256 bits, só o **SHA-256** fica no banco, validade 48 h, **uso único**, invalidado por uso, revogação, substituição ou desativação; o link usa **`APP_URL`** (nunca o cabeçalho Host). A pessoa define a **própria senha** (mín. 10, letras e números). Ao ativar, o perfil e o estado são lidos de novo do banco.
- **Funcionários:** somente o proprietário (Usuários e permissões → *Convidar funcionário*; reenviar/revogar convite; desativar; encerrar sessões). Gerente e garçom recebem 403 também pela API. Garçom e estação nascem com o mínimo do perfil.
- **Conta da estação** (perfil *Estação de impressão*): identidade **própria e revogável**, permissão mínima (`station`: abrir a página da estação, batimento, reserva, envio e assinatura). Não acessa pedidos, usuários, configurações nem layouts. Use essa conta no computador da impressora em vez da conta do proprietário; desativar a conta derruba o acesso.
- **E-mail:** `MAIL_DRIVER=log` grava o e-mail em `storage/mail/*.eml` (**nada é enviado**; é o procedimento local excepcional, só com o proprietário presente). Para envio real configure SMTP em `storage\homologacao\env.ps1` (fora do Git): `MAIL_DRIVER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USER`, `MAIL_PASS`, `MAIL_FROM`. O cliente SMTP foi testado contra um servidor falso (AUTH, MAIL FROM, RCPT TO, DATA, acentos), **não** contra um provedor real. Opções gratuitas (condições a confirmar no site): Brevo (relay SMTP, 300 e-mails/dia, sem cartão; remetente verificado) e Resend (100/dia, 3.000/mês; remetente próprio só após verificar domínio). **Não** use a senha pessoal do Gmail. O remetente precisa estar verificado no provedor; um e-mail aceito pelo serviço **não** prova chegada à caixa de entrada.

## Disponibilidade (computador como origem do serviço)
`bin\homologacao.ps1 start|stop|status|url|backup` — supervisor em segundo plano que mantém **app PHP + túnel Cloudflare**, reinicia se caírem (verifica `/saude`), faz rotação de logs (5 MB × 5) em `storage\homologacao\logs\` e grava a URL ativa em `storage\homologacao\url.txt`. O servidor embutido do PHP é a única opção sem instalar nada no Windows: serve para o tráfego baixo desta homologação, **não** para produção.
- **Dependem do computador:** ligado, sem suspender, com internet; supervisor, app, `cloudflared`; para imprimir: QZ Tray aberto e a página da estação aberta e iniciada.
- **Túnel rápido** (`*.trycloudflare.com`): endereço sorteado pelo serviço, **muda ao reiniciar o túnel**; sem garantia de disponibilidade. Endereço fixo exige túnel **nomeado** + domínio na Cloudflare (a decidir).
- **Backup rotativo:** `bin\homologacao.ps1 backup` → `D:\Pizzaria_Bianca-backups\homologacao\` (14 cópias + SHA-256). Restauração verificada em banco separado (560 linhas, contagens idênticas). Agendar no Agendador de Tarefas e iniciar com o Windows **muda o sistema e só com autorização**.
