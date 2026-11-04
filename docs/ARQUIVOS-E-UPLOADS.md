# Arquivos, mídia e uploads — o que vai para o Git e o que exige backup separado

| Classe | Onde | No Git? | Backup/observação |
|---|---|---|---|
| **Código** | `src/`, `views/`, `bin/`, `migrations/`, `database/*.php`, `tests/` | Sim | Git |
| **Assets públicos da marca** | `public/assets/brand/`, `public/assets/fonts/`, favicons | Sim | Logo oficial transparente + derivadas (`bin/build-brand.php`; originais em `brand/original/`, fora do docroot) |
| **Fotos do cardápio (PDF/loja)** | `public/assets/img/` | Sim | Fotos reais da Bianca extraídas do PDF |
| **Fotos do iFood autorizadas** | `public/uploads/ifood/` (derivados ≤ 1200 px) | Sim (única pasta de `uploads/` versionada) | Uso autorizado pelo usuário em 04/11/2026 (conversa); guarde a autorização por escrito. Referenciadas pelo catálogo (`/uploads/ifood/…`) |
| **Originais do iFood** | `storage/ifood/original/` | **Não** | Só no ZIP de backup (proveniência: URL e data em `channel_observations`) |
| **Uploads feitos no painel** | `public/uploads/*.jpg` | **Não** | **Backup próprio** (cópia de pasta) junto com `php bin/backup.php`; disco persistente na hospedagem |
| **Dados de pessoas** (clientes, pedidos, usuários, hashes, sessões, tokens) | banco | **Não** | `php bin/backup.php` (JSON) guardado fora da pasta pública, acesso restrito (`docs/BACKUP-LGPD.md`) |
| **Catálogo sem dados pessoais** | `database/export/catalogo-AAAA-MM-DD.json` | Sim | gerado por `bin/export-catalog.php`; restaurado por `bin/restore.php` |
| **Dados observados em canais externos** | `data/ifood/*.json` | Sim | preços do iFood **observados** (referência, não preço do site) |
| **Documentos privados/operacionais** | — (não existem hoje) | **Não** | Nunca em `public/`: se surgirem (notas, contratos), usar pasta **fora do docroot** servida por rota autenticada |
| **Temporários e testes** | `storage/mail/`, `storage/logs/`, `storage/backups/`, `*.sqlite*`, `storage/dev-credentials.txt` | **Não** | descartáveis; `bin/scan-secrets.php` falha se aparecerem no commit |
| **Evidências de validação** | `docs/evidencias/`, `docs/demonstracao/`, `docs/validacao/` | Sim | capturas e vídeos gerados nos testes (sem dados pessoais reais) |

Regras: **URLs não substituem arquivos** (copie as pastas). `public/uploads/.htaccess` bloqueia execução de PHP. Uploads privados **não** podem usar pasta pública.
