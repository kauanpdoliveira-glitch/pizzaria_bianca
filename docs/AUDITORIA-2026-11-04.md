# Auditoria de desempenho e aceitação automática — 04/11/2026

Ambiente: Vercel (`pizzariabianca.vercel.app`, projeto `pizzaria_bianca`) + Supabase PostgreSQL (us-west-2).

| # | Problema | Evidência | Impacto | Correção |
|---|---|---|---|---|
| 1 | Função da Vercel em Washington (`iad1`), banco em Oregon | `X-Vercel-Id: gru1::iad1`; `/saude` ≈ 700 ms | Cada conexão/consulta cruzava o país | `"regions": ["pdx1"]` em `vercel.json`; `/saude` ≈ 245 ms |
| 2 | N+1 na API da gestão (itens, opções, eventos, comanda por pedido), chamada a cada 5 s | `Orders::list` → `hydrate` por pedido | Dezenas/centenas de consultas por atualização | `Orders::hydrateMany`/`board` (3 consultas), comandas em lote |
| 3 | Falta de índices em chaves de junção/filtro | migrations 001–011 | Varreduras em `order_items`, `order_events`, `print_jobs` | Migração `012_performance_indexes.sql` (reversível: `DROP INDEX IF EXISTS`) |
| 4 | Polling baixava o quadro completo mesmo sem mudança | resposta de ~17 KB | Banda e consultas à toa | `?rev=`: sem mudança devolve ~0,2 KB |
| 5 | Cardápio público sem cache | `/api/menu` ≈ 470 ms | Lento para cada visitante | Cache de borda 15 s (`s-maxage=15`); preço/disponibilidade revalidados no servidor ao pedir |
| 6 | Pedido do site dependia de aceite manual | status `received` | Atraso e dependência do painel aberto | Aceitação no servidor na mesma transação (`auto_accept`, padrão ligado) |
| 7 | Alerta sonoro só com o 1º clique e sem memória | `admin.js` | Sons repetidos ou perdidos | Barra de som, volume, teste, memória entre abas (localStorage), aviso de pedidos perdidos |

## Medições (da máquina do desenvolvedor ao site publicado, 5–6 tentativas)
| Rota | Antes | Depois |
|---|---|---|
| `/saude` | ~700 ms | ~245 ms |
| `/` | ~1250 ms | ~275 ms |
| `/cardapio` | ~700 ms | ~390 ms |
| `/api/menu` | ~1200 ms | 65–250 ms (cache HIT) |
| `/admin/api/orders` (incremental) | não medido antes | ~250 ms, 0,2 KB |

O piso de ~240 ms é a distância rede→Oregon, não a aplicação. Picos de 600–850 ms ocorrem em partida a frio.

## Reversão
- Código: `git revert` dos commits `cada6fa`, `b285693`, `bceef79`, `5422e10`.
- Índices: `DROP INDEX IF EXISTS idx_order_items_order, idx_order_item_options_item, idx_order_events_order, idx_orders_status, idx_orders_created, idx_orders_customer, idx_print_jobs_order, idx_print_jobs_status;` e apagar a linha `012_performance_indexes.sql` de `schema_migrations`.
- Backup anterior à migração: `storage/backups/bianca-20261104-234140.json` (fora do Git).
