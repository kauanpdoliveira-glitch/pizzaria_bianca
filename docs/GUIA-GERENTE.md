# Guia rápido da gerente

**Entrar:** `seusite/admin` com seu e-mail e senha.

## Pedidos (tela inicial)
- Clique em **🔕 Ativar alerta sonoro** uma vez por sessão (o navegador exige esse toque). Deixe a aba aberta.
- Pedido novo aparece em **Novos**, destacado e com bipe. O topo mostra se está **Conectado**; se ficar vermelho "Sem conexão", os dados podem estar velhos.
- Botão grande avança o pedido: **Aceitar → Iniciar preparo → Pronto/Saiu p/ entrega → Concluir**. Ao aceitar você pode ajustar a estimativa em minutos.
- **Confirmar pagamento** só depois de conferir o dinheiro/maquininha/PIX. Enviar o pedido ou comprovante **não** confirma sozinho.
- **Cancelar** exige o motivo. Se aparecer "alterado por outra pessoa", a tela já foi atualizada: confira e repita.
- **Detalhes** mostra o histórico (quem mudou e quando) e permite reimprimir.

## Pausar / horário
Configurações → desmarque **Receber pedidos** para pausar na hora. Horários, bairros e taxas também ficam lá (administrador).

## Cardápio
Cardápio → **Disponível · pausar** esconde um item sem apagar. Clique no nome para editar preço, descrição, foto e tamanhos. **Adicionais/bordas** por categoria. Pedidos antigos mantêm os preços da época.

## Vendas
Vendas → escolha o período. Tabelas separam *valor dos pedidos*, *pagamentos confirmados* e *pendentes*; cancelados ficam à parte. **Exportar CSV** abre no Excel. Não substitui contabilidade nem nota fiscal.

## Impressora
Se aparecer "Agente de impressão offline", veja `IMPRESSAO.md`. Um trabalho **Incerto** significa: confira o papel antes de reenviar (pode duplicar).

## Novo gestor de pedidos (tela inicial)
- **Menu à esquerda:** só aparecem os módulos que existem e que seu perfil permite. A bolinha mostra se a loja está aberta (horário e pausa vêm de Configurações).
- **Topo:** a impressora fica verde (agente online), vermelha (offline) ou amarela (nunca conectou) — passe o mouse para ver detalhes. O sino liga o alerta sonoro e mostra quantos pedidos novos aguardam.
- **Filtros:** Novos, Em preparo (inclui "Aceito"), Prontos (retirada), Em entrega e Concluídos/cancelados de hoje. Os números respeitam a busca. A lista segue uma ordem fixa: do pedido mais antigo para o mais novo.
- **Ajustar visualização:** densidade compacta/confortável e uma ou duas colunas (guardado só neste navegador).
- **Detalhes à direita:** clique em um pedido. O botão principal muda conforme a etapa (Aceitar pedido → Iniciar preparo → Marcar pronto → Concluir). **Imprimir** envia uma nova via à *fila*; a confirmação só aparece quando o agente informar "Impressa". Em celular, os detalhes abrem em tela cheia e "Voltar à lista" mantém seu filtro.
- Se outra pessoa mexer no mesmo pedido, aparece um aviso e a tela é atualizada — repita a ação se ainda fizer sentido.

## Novo pedido (balcão ou telefone)
Botão **Novo pedido** na barra do gestor: escolha categoria e produto, configure tamanho/sabores/adicionais e adicione. Informe retirada ou entrega (bairro, rua e número), pagamento e observações. Nome e telefone são opcionais; sem nome o pedido fica como "Balcão". O preço e as regras são calculados pelo servidor, o pedido entra como **Novo** com pagamento **pendente**, vai para a fila de impressão e fica registrado "cadastrado manualmente" com seu nome. Esse cadastro **ignora o horário e a pausa da loja**. Clique duplo não cria dois pedidos.

## Detalhes do pedido: tempos, endereço e ações
- **Três tempos diferentes:** "Recebido … (há X)" = desde a chegada do pedido; a faixa "Aceito/Em preparo há X" = tempo na etapa atual; "Previsão de atendimento" = recebimento + estimativa (fica vermelha se atrasar). Na lista, o tempo à direita é o desde o recebimento.
- **Origem e cliente:** "Site" ou "Cadastro manual (quem digitou)"; a contagem de pedidos do cliente abre o histórico dele (Clientes).
- **Editar endereço:** em pedidos de entrega ainda não prontos (rua, número, complemento, referência). Bairro/taxa/total não mudam; fica no histórico. Depois, reimprima a comanda.
- **Botões por etapa:** Aceitar pedido → Iniciar preparo → Encaminhar para entrega / Marcar pronto para retirada → Confirmar entrega concluída / Confirmar retirada. Concluir **não** confirma o pagamento.
- Se o pedido aberto sair do filtro (mudou de etapa ou não casa com a busca), aparece um aviso azul com atalho para o filtro certo; os detalhes continuam abertos.

## Editar itens do pedido
Nos detalhes, **Editar itens** (antes de o pedido ficar pronto **e** com o pagamento ainda pendente): altere quantidades e observações, remova itens ou adicione novos. Itens que já estavam no pedido **mantêm o preço da compra**; itens novos usam o cardápio de hoje. O servidor recalcula o total (bairro/taxa não mudam; se o troco informado ficar menor que o novo total, ele é removido) e registra tudo no histórico com seu nome. O pedido não pode ficar sem itens — para desistir, use Cancelar. Depois de editar, **reimprima a comanda** (a original já impressa não muda). Se o pagamento já foi conferido, os itens ficam bloqueados.
