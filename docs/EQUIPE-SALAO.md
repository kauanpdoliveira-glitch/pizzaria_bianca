# Área da equipe, salão e caixa

## Perfis e acesso
| Perfil | Entra por | Cai em | Pode |
|---|---|---|---|
| **Proprietário** | `/equipe` | `/admin` | Tudo, e **só ele**: usuários e permissões, auditoria de acessos, configurações sensíveis (horários, bairros, pagamentos, token do agente de impressão), painel executivo. |
| **Gerente** | `/equipe` | `/admin` | Pedidos, salão, caixa (conferir e fechar), cardápio, clientes, vendas, impressão — conforme as permissões que o proprietário marcou. **Não** vê Usuários, Auditoria, Executivo nem Configurações (nem por requisição direta: 403). |
| **Garçom** | `/equipe` | `/garcom` | Mesas e comandas: abrir mesa, nova comanda, lançar itens, solicitar conta, informar recebimento (pendente). **Não** confirma pagamento, não fecha conta, não vê o painel. |

O destino vem do cadastro no servidor. Não há cadastro público, seletor de cargo, nem cargo em URL/armazenamento do navegador. Desativar, trocar perfil/permissões ou senha, ou "Encerrar sessões" derruba as sessões abertas da pessoa. Mensagem de login é genérica (não revela se o e-mail existe) e há bloqueio após 8 tentativas em 15 min. **Recuperação de senha:** o próprio funcionário usa "Esqueci minha senha" em `/equipe` (link de uso único por e-mail, 60 min; não altera cargo, não reativa conta, encerra sessões). Exige `MAIL_DRIVER=mail` ou `smtp` em produção. O proprietário também pode definir nova senha. Não há 2FA.

Matriz explícita: `Auth::ROLE_DEFAULTS`, `Auth::GRANTABLE`, `Auth::OWNER_ONLY` (`src/Auth.php`). Fora da matriz, é negado.

## Criar o proprietário (único caminho)
No servidor: `php bin/create-admin.php email "Nome"` (perfil padrão: proprietário; senha digitada no terminal, mín. 10, letras e números). Sem senha padrão e sem "primeiro usuário vira dono". Contas existentes com perfil antigo "admin" viram "proprietário" na migração 002. Gerentes e garçons: **Usuários e permissões** no painel (só proprietário).

## Salão: mesa, atendimento e comanda
- **Mesa** é o lugar. **Atendimento** é o grupo (id próprio). **Comanda** é a conta, vinculada a um atendimento e à mesa. Cada **rodada** de itens vira um pedido de produção (origem "Salão") no gestor, na cozinha/bar e na impressão. Pagamentos ficam na comanda.
- **Abrir mesa**: nome + pessoas (+ nº físico opcional). Mesa com atendimento **ativo** exige escolha explícita: adicionar comanda ao grupo atual **ou** abrir atendimento independente (se o proprietário permitir).
- **Fechamento pendente** na mesa (ex.: mesa 04): uma **Nova comanda** (ex.: 027) cria atendimento e comanda independentes; o sistema mostra a pendência; nada da conta antiga é fechado, transferido ou quitado. Fechar a conta antiga **não** libera a mesa se há grupo ativo. A mesa aparece como "Em atendimento · 1 fechamento pendente".
- O número físico não pode estar em duas comandas **abertas** (garantido no banco); depois de fechada, pode ser reutilizado (o histórico usa ids internos).
- Novas rodadas enviam **só os itens novos**; bebidas vão ao balcão/bar e o resto à cozinha (setor por categoria, em Cardápio). Alterar/cancelar item já enviado só pelo gestor (com motivo e histórico) e o setor recebe comanda "PEDIDO ALTERADO/CANCELADO".

## Receber e fechar (Caixa)
1. Garçom **solicita a conta** (não lança mais itens). 2. Garçom **informa** o recebimento (fica pendente). 3. Gerente/caixa **confere**: confirma ou recusa. 4. Total confirmado = total da conta → **fechar comanda**. O atendimento fecha quando todas as comandas fecham. Descontos, remoção da taxa de serviço, estorno e reabertura exigem permissão e **motivo** e ficam no histórico. "Estorno" é só registro no sistema; devolver o dinheiro é feito fora. Taxa de serviço: só existe se o proprietário configurar o percentual (Configurações → Salão). Pagamentos parciais e várias formas são aceitos; não há divisão de itens por pessoa.
- **Turno**: abrir com valor; suprimento e sangria com motivo; fechar informando o dinheiro contado (esperado = abertura + dinheiro confirmado + suprimentos − sangrias; diferença exige observação). O turno cobre o **salão**; pagamentos de entrega/retirada seguem no gestor.

## Painel do proprietário
**Visão executiva** (`/admin/executivo`): faturamento por canal (entrega, retirada, salão) com comparação ao período equivalente anterior; recebido × pendente; formas de pagamento; descontos e cancelamentos por motivo; operação do salão agora; mais vendidos; itens indisponíveis; saúde da operação (atrasos, falhas/fila de impressão, agente, último backup, turno). Exportação CSV (comandas do salão e pedidos). **Critérios**: pedidos de produção do salão não são venda (a venda é a comanda fechada) — sem contar duas vezes. Lucro/margem/CMV não existem (sem custos).

## Verificado × não verificado
Verificado (testes automáticos e navegador local, SQLite): permissões e sessões, abertura idempotente, cenário mesa 04/comanda 027, unicidade do nº físico, rodadas só com itens novos, fluxo garçom→caixa→fechamento, turno, relatórios sem dupla contagem, telas em 390 px e desktop. **Não verificado**: execução das migrações em **MySQL/HostMia** (escritas em SQL portátil, nunca rodadas em MySQL), concorrência real entre vários aparelhos (usa `BEGIN IMMEDIATE`/`FOR UPDATE`, sem teste paralelo), impressão física, 2FA (inexistente).
