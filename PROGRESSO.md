# Bianca Pizzaria — decisões, progresso e pendências

## Decisões
- **Stack:** PHP 8.2+ puro (sem Composer, sem framework) + PDO. MySQL/MariaDB em produção, SQLite só em dev/testes. Escolha por compatibilidade máxima com hospedagem compartilhada: não precisa de SSH, Node nem processo persistente.
- **Tempo real:** *polling* a cada 5 s no painel (`/admin/api/orders`). Sem WebSocket. Recuperação após queda é automática (a lista inteira é relida).
- **Dinheiro:** inteiros em centavos. Datas: `Y-m-d H:i:s` no fuso `APP_TZ`.
- **Segurança:** preços sempre recalculados no servidor (`Pricing::quote`); idempotência por cabeçalho `Idempotency-Key` + UNIQUE; bloqueio otimista por `orders.version`; CSRF no painel; senhas com `password_hash`; limite de tentativas de login; CSP sem scripts inline.
- **Meia a meia:** média dos sabores, arredondada para cima ao centavo (PDF: "será cobrado o valor médio"). Regra alternável (maior valor) em Configurações.
- **Impressão:** fila persistente no banco + agente local (`bin/print-agent.php`) que busca trabalhos (saída apenas) e envia ESC/POS. Erro após envio => estado "incerta".
- **Pedido nº:** é o `id` sequencial; acompanhamento por token aleatório de 128 bits.

## Feito e verificado
- `php tests/run.php` → 33 testes (preço, opções, idempotência, transições, conflito 409, relatórios, fila de impressão, ESC/POS, senhas).
- Fluxo manual no navegador local: monta pizza meia a meia + borda, checkout, 3 envios simultâneos → 1 pedido; acompanhamento; painel: aceitar → preparo → saiu p/ entrega; 409 em edição concorrente; 419 sem CSRF; APIs do painel 401 sem login.
- Agente de impressão em modo arquivo contra o servidor local: 2 comandas (cozinha/balcão) impressas e marcadas.
- Backup/restauração JSON testados em banco separado.

## Redesenho visual (direção "01 — Clássica")
- Só o site público mudou: `public/assets/site.css` (tokens em `:root`), `views/layout.php`, `home.php`, nova `historia.php` (rota `/historia`). Painel (`admin.css`) e regras de negócio intocados. Fontes/imagens: `docs/FONTES.md`.
- Verificado (navegador local): sem rolagem horizontal em 360/390/768/1440 px (home, história, cardápio); carrinho (preço, +/−, remover), checkout com taxa, pedido #2 gravado uma vez apesar de duplo envio, painel recebeu, "Aceitar" refletiu no acompanhamento; 33 testes passam. Não verificado: leitor de tela, contraste automatizado, Lighthouse, 1440 px com captura nítida (painel do navegador é pequeno).

## Gestor de pedidos (direção "01 — Essencial")
- Casco administrativo novo (`views/admin/layout.php`, `icons.php`, `admin.css` com tokens): menu lateral, cabeçalho com impressora/sino/usuário; todas as telas do painel o herdam. O quadro Kanban foi substituído por lista + painel de detalhes (`board.php`, `admin.js`). Novo no backend: `GET /admin/api/orders/{id}`, `jobs` por pedido e `agent` (estado real do agente de impressão) em `/admin/api/orders`.
- Verificado no navegador local (dados de teste): contagens e busca; seleção e filtro preservados no polling; pagamento independente do preparo; aceitar com estimativa; Imprimir → fila (estado "Na fila", agente offline sinalizado); conflito 409 com aviso e atualização; perda de conexão (banner, ações desativadas, recuperação); novo pedido chegando sozinho; 390 px (detalhe em tela cheia, voltar mantém filtro, menu recolhível, sem rolagem horizontal), 1100 e ~1672 px; APIs 401 sem login; gerente sem Configurações/Usuários.
- **Novo pedido manual implementado** (`Orders::create(..., $manualBy)`, `POST /admin/api/orders`): testado no navegador (item meia a meia com borda, entrega, envio duplo = 1 pedido, validações) e em 2 testes automáticos. Módulos da referência ainda **ausentes** (fora do menu): Mesas e comandas, Cozinha, Caixa, Entregas — exigem subsistemas novos. "Desempenho" = módulo Vendas existente.
- Não verificado: leitor de tela, contraste automatizado, impressão física, navegação por teclado em todas as telas.

## Ajustes inspirados no GIF de referência (Cardápio Web)
- **Limitação:** o anexo era um WebP estático (1 quadro, 692×388); só o que aparece nesse quadro foi considerado. Nada foi inferido sobre animações ou o que cada botão faz.
- Incorporado: tempo desde o recebimento × tempo na etapa × previsão (distintos); "N pedidos" do cliente com link (permissão Clientes); origem (site/manual); rótulos de ação por modalidade/etapa; endereço editável antes de pronto, com histórico (`Orders::updateAddress`, `POST /admin/api/orders/{id}/address`, controle de versão); autor/hora dos jobs de impressão; aviso quando o pedido sai do filtro; rolagem da lista preservada; respostas atrasadas da API descartadas (polling e carga avulsa).
- Verificado (navegador local): troca rápida entre pedidos com resposta atrasada sem misturar dados; recarregar não duplica linhas; pizza ½+½ com borda e observação nos detalhes; status refletido no acompanhamento do cliente; 390 px sem rolagem horizontal; 37 testes.
- **Edição de itens implementada** (`Orders::editItems`, `POST /admin/api/orders/{id}/items`): qtd, observação, remover, adicionar; só antes de pronto e com pagamento pendente; preços antigos preservados; histórico; 2 testes automáticos + navegador (envio duplo aplica uma vez, API recusa com pagamento confirmado, 419 sem CSRF). Seletor de produtos compartilhado com "Novo pedido".
- **Pendente (decisão ou integração):** entregador próprio e serviço externo de entrega (o botão do GIF é de um fornecedor; exigiria contrato/API); edição de tamanho/sabores de um item existente (hoje: remover e adicionar de novo); descontos; compartilhar pedido; notificação ao cliente.

## Portal da equipe, salão e caixa (03 — Lista inteligente)
- **Perfis:** Proprietário / Gerente / Garçom (`Auth`), matriz explícita, permissões por usuário (`users.perms`, só dentro do teto do perfil), `session_version` derruba sessões ao mudar acesso. Login em `/equipe` (alias `/admin/login`); destino por cadastro. Usuários, Auditoria, Executivo e Configurações: só proprietário (`Team`, `Auth::OWNER_ONLY`).
- **Salão:** migrações 002/003 (`dining_tables`, `visits`, `tabs`, `tab_payments`, `cash_shifts`, `cash_moves`, `floor_events`; `orders.origin/visit_id/tab_id/table_number`; `categories.sector`). `Floor` (regras) e `Cash` (turnos). Rodada = pedido `dine_in` via `Orders::persist` (reuso de gestor, cozinha e impressão; ESC/POS próprio do salão). Garçom em `/garcom` (`garcom.js`, seletor compartilhado `picker.js`); Caixa `/admin/caixa`, comanda `/admin/comanda/{id}`, Salão `/admin/salao`, Executivo `/admin/executivo`.
- **Testes:** 54 automáticos (equipe, matriz, sessões, abertura idempotente, mesa ativa × pendente, nº físico, rodadas, permissões do garçom, recebimentos, taxa/desconto, estorno/reabertura, turno, relatórios sem dupla contagem). No navegador: login por perfil (garçom→/garcom, gerente→/admin), 403 do gerente em usuários/auditoria/executivo, garçom abre mesa (3 envios = 1), lança 2 rodadas, solicita conta e informa recebimento; gerente abre turno, confirma, registra restante e fecha; cenário mesa 04 com aviso e escolha explícita; recusa de comanda física duplicada. Bug achado e corrigido no teste real: criação de usuário pelo formulário descartava as permissões.
- **Pendências:** migrações **não testadas em MySQL**; concorrência real entre aparelhos não testada; sem 2FA nem recuperação de senha por e-mail; sem divisão de itens por pessoa; sem estoque/fiscal/conciliação; sem entregador próprio; turno de caixa não inclui entrega/retirada; "tempo de ocupação" só como média de atendimentos fechados.

## Pendências reais
- **Hospedagem:** versões de PHP/MySQL, SSH, cron e docroot da HostMia **não confirmados** (sem documentação oficial acessível). Ver `docs/DEPLOY-HOSTMIA.md`.
- **Impressão física:** modelo da impressora, conexão (USB/rede) e SO do PC da loja desconhecidos. Nada foi impresso em hardware real. Acentos são convertidos para ASCII (compatível com qualquer impressora).
- **Cardápio:** SALAMINHO Média está R$ 94,90 no PDF (parece erro; Brotinho 54,90 / Grande 129,90). Mantido como no PDF — confirmar. Doses, drinks (caipirinha etc.) e bombonière **não foram importados** (cadastrar no painel se forem vendidos por entrega).
- **Loja:** bairros/taxas/pedido mínimo (nenhum dado real → cadastrar em Configurações), horário (18h–23h veio do Instagram, confirmar), chave PIX, WhatsApp.
- **Instagram:** só a bio foi acessível (125 mil seguidores, horário 18h–23h, iFood, linktr.ee). Fotos do perfil não foram usadas — apenas imagens do PDF (autoria/licença a confirmar com a loja). Logotipo oficial aplicado (150 px; pedir versão maior/vetorial).
- **Não implementado:** pagamento online, descontos/cupons (coluna existe, valor 0), edição de pedido após criado, notificação ao cliente, ícone/PWA.
- Imagens de pratos principais sem foto confiável foram deixadas sem imagem (placeholder) para não associar foto errada.

## Marca e responsividade
- Logo branca transparente verificada (alfa real, sem halo) e integrada por `View::brand()` + `brand.css`: cabeçalho/rodapé do site, faixa vermelha no menu lateral do painel, cabeçalho vermelho do garçom, topo do login, og:image. Detalhes e pendências (logo escura para impressão, favicon): `docs/MARCA.md`. Derivados por `php bin/build-brand.php`; originais em `brand/original/`.
- Corrigido: salto para a categoria ao vir da home (`/cardapio#cat-N` não rolava porque o cardápio é renderizado depois); faixa da marca encolhia no menu lateral (flex).
- Verificado (navegador local): sem rolagem horizontal em 360 px em 5 páginas públicas/garçom e 8 do painel; 390, 1024 e 1440 px nas telas principais; pedido de teste concluído (1 pedido, envio duplo) ; 54 testes. Não medido: desempenho (Lighthouse), leitor de tela, 768 px em todas as telas do painel.

## Cardápio Ágil (Conceito 04) + Catálogo administrativo
- **Backend:** migração 004 (`products.hidden`, `categories.parent_id/max_flavors/allow_removals`, `options.description/size_restricted`, `product_ingredients`, `password_resets`); `Pricing` reescrito (1–2 sabores por categoria, mesmo grupo raiz, borda por tamanho/restrição, retirada por sabor, oculto/em falta barrados no servidor). Ingredientes das 23 pizzas transcritos do PDF (`database/seed_ingredients.php`, idempotente).
- **Cliente:** abas de categoria + subcategorias + busca + grade de fotos; montador em 3 etapas (tamanho → sabores com contador → personalizar: retirar por sabor, borda, observações); sacola com Editar; aviso de preço alterado no checkout; ilustrações neutras no lugar de fotos ausentes.
- **Admin:** Catálogo com abas Produtos/Complementos/Opções, categorias e subcategorias, status Ativo/Em falta/Oculto (select), reordenar ▲▼, busca, regras da categoria, ingredientes no produto.
- **Outros:** favicon/ícones com a logo completa; login local do proprietário (`storage/dev-credentials.txt`, ignorado pelo git); recuperação de senha por e-mail (`Mailer` log/mail/smtp, `PasswordReset`); backup passou a incluir todas as tabelas (corrigido: salão/caixa/ingredientes ficavam de fora); retirada exibida como "−" no gestor, garçom, comanda e página do pedido.
- **Testes:** 62 automáticos. Navegador: montagem 1/2/3 sabores, retirada só do sabor certo, borda e preço, pedido salvo uma vez com tudo na comanda, admin→público (criar produto, Oculto, Em falta), recuperação de senha de ponta a ponta, login local, sem rolagem horizontal em 360/768/1024/1440, persistência após reiniciar.
- **Pendências:** MySQL/HostMia nunca executado; `mail`/`smtp` sem servidor real; fotos reais (62 itens: `docs/FOTOS-PENDENTES.md`); bordas além do Catupiry e pizzas doces não existem no PDF; tablets/garçom em uso real; impressão física; desempenho (Lighthouse) e leitor de tela não medidos.

## 04/11/2026 — Movimento, validação por perfil e backup
Sistema de movimento (docs/MOVIMENTO.md); validação completa com Chrome real, perfis (68/68 HTTP) e acessibilidade (69/69 automatizado) em docs/VALIDACAO-2026-11-04.md; 9 defeitos corrigidos (acompanhamento do pedido, CLS do cardápio, garçom em /admin/salao, impressão, h1 duplicada…); demonstração em vídeo em docs/demonstracao/. Pendências externas: teclado virtual, toque real, leitor de tela, MySQL/HostMia, impressora.

## 04/11/2026 — Otimização, concorrência e publicação
Build de produção (minificação, split core/shop, WOFF, hero 640 px), correções de concorrência (idempotência dentro da transação; txImmediate), exportação de catálogo sem dados operacionais, higiene para repositório público (.gitignore, scan de segredos), README e docs de hospedagem (Hostinger/Vercel). Detalhes: docs/DESEMPENHO.md e docs/VALIDACAO-2026-11-04-publicacao.md. Pendências externas: MySQL real, Hostinger, Vercel, impressão (QZ3 a confirmar), teclado virtual/toque/leitor de tela.
