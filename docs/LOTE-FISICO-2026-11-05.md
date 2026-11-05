# Lote físico de teste — 05/11/2026 00:41–00:42 (Vercel, estação "PC de casa — teste")

Autorização: até 3 páginas A4 na Epson L3210, identificadas como "TESTE — NÃO PRODUZIR", executado **uma única vez**.

| Item | Valor |
|---|---|
| QZ Tray | v2.3.0, modo manual (sem certificado/assinatura; sem `override.crt`) |
| Impressora | EPSON L3210 Series (USB), formato HTML A4, 1 via por destino |
| Pedido de teste | #14 (site, retirada, dinheiro): À Moda Média (obs. "TESTE sem cebola"), Heineken 600 ml, Mini Pudim Artesanal |
| Trabalhos (únicos) | #33 cozinha, #34 copa, #35 balcão — todos `original`, 1 via |
| Fila antes | `queued`: 3 (só este lote); `held`: 12 (intactos) |
| Resultado técnico | cozinha 00:42:11, copa 00:42:17, balcão 00:42:23: "Enviado ao subsistema de impressão" → `printed` |
| Depois | processamento **parado**; `held`: 12; nenhum trabalho pendente |

"Enviado ao subsistema" significa que o QZ aceitou o envio. **Saída física e legibilidade dependem da confirmação visual do proprietário** (não registrada aqui).
Conteúdo esperado: balcão = os 3 itens, dados do cliente, totais e pagamento; cozinha = só a pizza e suas personalizações/observação; copa = só bebida e sobremesa (Sobremesas → Copa). Nenhuma via vazia; nenhuma duplicação (job_key único).
Não repetir o lote: o pedido #14 não deve ser cancelado (geraria vias de cancelamento).
