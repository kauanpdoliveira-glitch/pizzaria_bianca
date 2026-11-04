# Backup, restauração e LGPD

## Backup
- Com SSH/cron: `php bin/backup.php /caminho/seguro` (JSON com todas as tabelas). Agende diariamente e copie também `public/uploads/`.
- Sem SSH: use o backup do banco do próprio painel da hospedagem (phpMyAdmin → Exportar) + baixe `uploads/` por FTP.
- O arquivo contém **dados pessoais** (nome, telefone, endereço): guarde fora da pasta pública, com acesso restrito, e mantenha poucas cópias (ex.: 14 dias).

## Restauração
1. Banco vazio + `.env` configurado.
2. `php bin/restore.php arquivo.json` (use `--force` para substituir um banco que já tem pedidos).
3. Recoloque `uploads/`. (Testado: backup → restauração em banco separado preservou pedidos, itens e cardápio.)

## LGPD (boas práticas aplicadas)
- Coletamos só nome, telefone e, para entrega, endereço. Sem CPF, e-mail ou data de nascimento. Cartão **nunca** é coletado.
- O carrinho fica apenas no aparelho do cliente; o rastreio usa link imprevisível e mostra só aquele pedido.
- Logs não gravam nome/telefone/endereço (apenas número do pedido).
- Acesso ao painel por perfil; ações administrativas ficam em `audit_log`.
- **Retenção:** defina com a loja por quanto tempo guardar pedidos antigos (sugestão a validar com contador/jurídico). Para atender pedido de exclusão, anonimize cliente e pedidos (`customers`/`orders`: nome → "Removido", telefone/endereço → nulo) — procedimento manual, ainda sem tela.
- Publique uma política de privacidade antes de ir ao ar (pendente — texto jurídico não incluído).
