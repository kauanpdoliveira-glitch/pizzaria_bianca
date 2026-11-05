# Matriz de pendências — 04/11/2026 (21:35)

Estado confirmado: repositório `kauanpdoliveira-glitch/pizzaria_bianca` (`main` = `16a089c` + alterações locais desta sessão), site local `http://127.0.0.1:8080`, túnel Cloudflare ativo, banco `storage/teste-tunel.sqlite` (migrações até 011). O computador **reiniciou às 21:14**: tudo foi religado depois (QZ, app supervisionado, túnel).

| # | Pendência | Tipo | Evidência | Impacto | Ação / resultado |
|---|---|---|---|---|---|
| 1 | Publicar na Vercel | **Bloqueio de plano** | Contas `kauanpdoliveira-7733` e `paingodev-7164`: Billing = *Hobby Plan Active*; fair use: Hobby só uso não comercial | Sem URL `*.vercel.app` legítima | Decisão do proprietário (Pro pago ou hospedagem PHP/MySQL). Nada criado |
| 2 | PHP na Vercel | Compatibilidade | Sem runtime PHP nativo; `vercel-php` é da comunidade; precisa MySQL externo | Mesmo com Pro o backend não roda “de graça” | Registrado em `docs/VERCEL.md` |
| 3 | Aprovações manuais do QZ | **A diagnosticar** | Conexão exigiu *Allow*; impressões também (autorização manual) | Impressão não é silenciosa | Precisa sessão real da estação (login no painel) para ver qual janela aparece com o `override.crt` instalado |
| 4 | Confiança QZ (`override.crt`) | Funcionalidade não testada | Arquivo copiado para `D:\QzTray\`; QZ reiniciado; resultado não observado | — | Testar conexão + 1 página com autorização do lote |
| 5 | E-mail real | Config. externa | `MAIL_DRIVER=log`; convites viram `.eml` local | Convites não chegam por e-mail | Escolher Brevo/Resend e criar credenciais |
| 6 | Trabalhos novos pendentes | **Risco tratado** | Pedidos #4–#6 (21:00–21:07) geraram 5 trabalhos `queued` | Imprimiriam ao iniciar a estação | **Retidos** (`held`) junto aos 3 antigos; liberar só com ordem explícita |
| 7 | Cookie/HTTPS atrás de túnel | **Defeito corrigido** | Túnel SSH não enviava `X-Forwarded-Proto`: cookie sem `Secure`, sem HSTS | Sessão menos protegida | `is_https()` considera `APP_URL` https; verificado: `Secure; HttpOnly`, HSTS, gzip |
| 8 | Supervisor em cadeia | **Defeito corrigido** | Recursão do script criou dezenas de processos e causou HTTP 429 | Túnel indisponível por ~50 min | Corrigido; app sobe mesmo sem túnel; reconexão com espera |
| 9 | `url.txt` compartilhado | **Defeito corrigido** | `tunel-ssh stop` apagou a URL do Cloudflare | URL perdida no status | Só remove se for `lhr.life` |
| 10 | Novo repositório | Dispensado | Instrução atual: usar o existente | — | Sem novo repositório |
| 11 | Endereço fixo | Config. externa | Quick Tunnel muda ao reiniciar | Links/QR mudam | Túnel nomeado exige domínio do proprietário |
| 12 | Impressão térmica (ESC/POS, corte, 58/80 mm) | Não testada | Só Epson L3210 em HTML A4 | — | Aguardam as impressoras da loja |
| 13 | Revisão completa (compra, admin, salão) | Coberta por testes | 103 unitários + 71 HTTP aprovados | — | Revisão visual em celular físico/leitor de tela **não** feita |
