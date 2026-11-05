# Corte para produção, tempo real e plano Vercel — 05/11/2026

## 1. Inventário (somente leitura, 05/11/2026)
| Fonte | Conteúdo | Classificação |
|---|---|---|
| `storage/bianca.sqlite` | 16 pedidos "Teste …", contas `*@demo.local` | **Dados de teste/desenvolvimento. Não migrar.** |
| `storage/teste-tunel.sqlite` (app local em `127.0.0.1:8080`, PID 7720, com o túnel) | 6 pedidos (#1–#6: "Teste Telefone", "kauan", "KAUAN PEREIRA DE OLIVEIRA", "Pedro", "Lc" ×2), 18 trabalhos de impressão (8 **retidos**, 10 impressos), 2 contas (`dono@teste.local` inativa e `kauango.dev@gmail.com`) | Origem da migração já feita. Todos os 6 pedidos são de teste; catálogo, configurações, layouts e contas já estão no Supabase. |
| Supabase (`public`) | Tudo acima + pedidos #7–#9 criados depois na Vercel (inclui o teste automático #9, cancelado) | **Fonte oficial dos dados.** Backup: `storage/backups/bianca-20261104-234140.json` (fora do Git). |

Validador: `php -d extension=pdo_pgsql bin/validar-migracao.php storage/teste-tunel.sqlite` (variáveis `DB_*` por ambiente; nunca em argumentos). As únicas divergências de contagem são pedidos/itens/eventos/trabalhos/auditoria criados depois no Supabase — esperado. Contas, catálogo, configurações e sequências coincidem.

## 2. O app local ainda sustenta a estação?
Sim, enquanto a página da estação estiver aberta no endereço local (`127.0.0.1:8080`, servidor `php -S` + túnel). A estação da Vercel **não está iniciada** e os 8 trabalhos retidos continuam retidos. Antes de parar o app local: abrir a estação em `https://pizzariabianca.vercel.app/admin/estacao/1`, conferir o mapeamento das impressoras e só então iniciar o processamento (um único consumidor por vez).

## 3. Procedimento proposto (depende de confirmação do proprietário)
1. Janela combinada: avisar a equipe; pausar "Receber pedidos" no app **local** (não há pedidos reais nele; todos os 6 são de teste).
2. Backup novo do Supabase (`php bin/backup.php`, fora do Git) e do SQLite local.
3. **Não copiar o banco local por cima do Supabase.** Nada precisa ser copiado: o Supabase já é mais novo. Se houver edição de catálogo/configuração feita só no local após 04/11 20:00, reaplicá-la manualmente pelo painel da Vercel.
4. Rodar `validar-migracao.php` e conferir contas, catálogo e configurações.
5. Parar o túnel e o `php -S` locais; abrir a estação na Vercel (QZ aberto; autorizações manuais do QZ ficam com o operador).
6. Só então: definir `APP_ENV=production` na Vercel (remove a faixa de homologação e o "TESTE — NÃO PRODUZIR" das comandas reais). Testes de impressão continuam marcados como teste.
7. Pedido de teste identificado pela URL pública (cancelar depois; trabalhos descartados).
**Reversão:** reativar o `php -S` + túnel locais (nada foi apagado), voltar `APP_ENV=staging` e restaurar o backup do Supabase (`bin/restore.php --force`, só com confirmação).

## 4. Tempo real — decisão
- **Supabase Realtime não será usado agora.** Motivo concreto: `postgres_changes` em `orders` exigiria expor a tabela ao navegador (RLS + JWT do Supabase Auth, que este sistema não usa — a autenticação é por sessão PHP). Canal *broadcast* público não é seguro só por trazer “mudou”. Canal privado exige JWT assinado com segredo privilegiado do projeto no servidor e políticas RLS em `realtime.messages`; é viável, mas aumenta a superfície de segurança e o custo operacional sem ganho proporcional ao tamanho da operação.
- **Implementado (seguro):** polling incremental por revisão (`?rev=`: resposta de ~0,2 KB quando nada mudou), agenda única (sem `setInterval` concorrente), 5 s em aba visível / 20 s oculta, recuo progressivo com jitter após falhas, retomada ao voltar à aba ou à rede, recuperação de pedidos recebidos durante a ausência (aviso único, sem tocar o histórico) e memória de notificações entre abas. **Não é tempo real**: o painel enxerga um pedido em até ~5 s.
- Medição (05/11): pedido #9 criado 23:44:53, visto como `accepted` pelo painel às 23:44:58 (≤ 5 s).

## 5. Plano Vercel (fontes: docs oficiais, 2026-09)
- Fair use: "Hobby teams are restricted to non-commercial personal use only. All commercial usage … requires either a Pro or Enterprise plan." Exemplos de uso comercial incluem processar pagamento e **anunciar a venda de produto ou serviço**.
- O projeto está no **Hobby** (painel da Vercel). Um site que recebe pedidos de uma pizzaria é uso comercial → **não elegível**. Opções: Pro (US$ 20/mês, 1 assento) ou outra hospedagem. Nada foi contratado nem contornado. Dúvida de enquadramento pode ir ao suporte da Vercel.
