> **Atenção:** este roteiro foi escrito para a **HostMia**. A hospedagem futura é a **Hostinger** (outra empresa): use-o só como base genérica de PHP + MySQL e veja `docs/HOSPEDAGEM.md`. Nada aqui foi verificado na Hostinger.

# Deploy na HostMia

> **Não confirmado:** não consegui acessar documentação oficial da HostMia, e o nome "Unlimited" não prova nenhum recurso. Antes de publicar, confirme no painel (ou me envie uma captura, **sem senhas**):
> 1. versão do PHP (precisa ≥ 8.2) e extensões `pdo_mysql`, `mbstring`, `gd`, `fileinfo`, `openssl`;
> 2. versão do MySQL/MariaDB e como criar banco/usuário;
> 3. se há SSH (opcional) e cron (opcional — o sistema não depende de cron);
> 4. se dá para apontar o domínio para uma subpasta (`public/`) e se há HTTPS gratuito (Let's Encrypt);
> 5. limite de upload (`upload_max_filesize` ≥ 4 MB).
>
> A aplicação foi desenhada para o caso mínimo: PHP + MySQL, sem processos persistentes, sem Node, sem Composer.

## Passo a passo (cPanel/FTP típico)
1. Crie o banco MySQL e um usuário só para ele (painel → MySQL). Anote nome, usuário e senha **fora do chat**.
2. Envie o projeto (sem `.env`, `storage/`, `tests/`) por FTP/Gerenciador de Arquivos. Estrutura recomendada:
   ```
   /home/USUARIO/bianca-app/   ← src, views, migrations, database, bin, storage
   /home/USUARIO/public_html/  ← conteúdo de public/ (index.php, assets/, uploads/, .htaccess)
   ```
   Em `public_html/index.php` o código já procura `../bianca-app` quando `../src` não existe.
   Se o domínio puder apontar para `public/`, mantenha tudo junto e aponte para `public/`.
3. Crie `bianca-app/.env` a partir de `.env.example` (`APP_ENV=production`, `DB_DRIVER=mysql`, credenciais, `APP_URL=https://seu-dominio`). Permissão 600.
4. Crie as tabelas:
   - **Com SSH:** `php bin/migrate.php && php database/seed_menu.php`
   - **Sem SSH:** envie `public_html/instalar-UMA-VEZ.php` (arquivo temporário abaixo), abra uma vez no navegador e **apague-o em seguida**.
5. Ative HTTPS e descomente o redirecionamento no `.htaccess`.
6. Crie o administrador (SSH: `php bin/create-admin.php ...`; sem SSH use o script temporário).
7. Entre em `/admin/login` → Configurações: horário, bairros/taxas, formas de pagamento, estimativas. Gere o token do agente em Impressão.
8. Faça um pedido de teste e cancele com motivo "teste".

### Script temporário (sem SSH)
Crie `instalar-UMA-VEZ.php` ao lado do `index.php`, com o conteúdo abaixo, **troque a senha**, abra uma vez e apague:
```php
<?php
require __DIR__ . '/../bianca-app/src/bootstrap.php';
Db::migrate();
require __DIR__ . '/../bianca-app/database/seed_menu.php';
if (!Db::val('SELECT 1 FROM users')) {
  Db::insert('users', ['name'=>'Administrador','email'=>'EMAIL_AQUI','password_hash'=>password_hash('SENHA_FORTE_AQUI', PASSWORD_DEFAULT),'role'=>'owner','active'=>1,'created_at'=>now()]);
}
echo 'Pronto. APAGUE este arquivo.';
```

## MySQL: o que foi (e não foi) verificado
- **Nunca executei as migrações em MySQL/MariaDB** (não havia servidor disponível aqui). Revisei o SQL: tipos `INT UNSIGNED` nas chaves e nas colunas que as referenciam, `utf8mb4`, índices únicos em colunas anuláveis (várias linhas `NULL` são aceitas), sem nomes reservados, consultas com `GROUP BY` compatíveis com `ONLY_FULL_GROUP_BY`, `SELECT … FOR UPDATE` onde há concorrência. Dessa revisão saiu **um defeito real, já corrigido**: o relatório executivo usava `strftime` (só SQLite).
- **DDL no MySQL não é transacional:** se uma migração falhar no meio, ela fica parcialmente aplicada. Por isso: (1) faça backup do banco antes; (2) rode primeiro num banco **vazio** de teste na própria HostMia; (3) só então no definitivo.
- **Depois de `php bin/migrate.php` (ou do script temporário), rode `php bin/check.php`**: confere PHP ≥ 8.2, extensões, pastas graváveis, conexão, migrações, tabelas/colunas esperadas e se existe proprietário. Sem SSH, peça ao suporte para rodar ou use o painel de PHP da hospedagem para executar o script uma vez.
- Se algo falhar, **me envie a mensagem de erro** (sem senhas) e a versão do MySQL/MariaDB e do PHP.

## Onde cada parte precisa funcionar (não presumir)
| Parte | Precisa de | Observação |
|---|---|---|
| Site, cardápio, painel, garçom, APIs | PHP ≥ 8.2 + MySQL/MariaDB + HTTPS | É **um único app PHP**: publicar só "o frontend" (ex.: Vercel) **não** entrega banco, sessões, fila nem impressão. Vercel não executa PHP/MySQL deste projeto sem reengenharia; o destino pretendido é a HostMia (ou outra hospedagem PHP+MySQL). |
| Fotos enviadas pelo painel | Pasta `public/uploads/` **persistente** e gravável | Em hospedagens efêmeras (serverless/containers sem volume) as fotos somem ao reiniciar. |
| Recuperação de senha | `MAIL_DRIVER=mail` (função `mail()`) ou `smtp` + `MAIL_FROM` de um domínio seu (SPF/DKIM no DNS) | O modo `log` **não envia nada**. `mail`/`smtp` não foram testados contra servidor real. |
| Impressão automática | Computador da loja com o agente (`bin/print-agent.php`), conexão **de saída** ao site | Impressora física/modelo não validados. |
| Cron / processos persistentes / WebSocket | **Nada disso é necessário** | Painel e garçom atualizam por polling. Backup agendado é opcional (cron ou rotina manual). |
| HTTPS, cookies | Certificado ativo; `APP_URL=https://...` | Cookie de sessão é `Secure` só sob HTTPS; ative o redirecionamento no `.htaccess`. Sem CORS: tudo é mesma origem. |

Rode `php bin/check.php` ao fim: ele confere PHP, extensões, pastas graváveis, banco, migrações (001–004), proprietário e o modo de e-mail.

## Atualizar
Envie os arquivos novos, preservando `.env`, `storage/` e `uploads/`; abra/execute `migrate` (novas tabelas entram sozinhas).

## Checklist de segurança
- `.env`, `src/`, `storage/` **fora** da pasta pública (ou bloqueados pelo `.htaccess` da raiz).
- Nenhum arquivo `instalar-*.php` no servidor.
- HTTPS ativo; senha forte; um usuário por pessoa.
- Backup agendado (docs/BACKUP-LGPD.md).
