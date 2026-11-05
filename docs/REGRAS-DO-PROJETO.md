# Regras permanentes do projeto

1. **Toda alteração solicitada** é enviada ao repositório `kauanpdoliveira-glitch/pizzaria_bianca` (branch `main`) **e publicada na Vercel** (projeto `pizzaria_bianca`) depois das verificações pertinentes. A entrega informa o **commit**, o **deployment** e a **validação pela URL pública** (`https://pizzariabianca.vercel.app`). Funcionar só no computador local ou pelo túnel **não** conclui a tarefa.
2. Antes de cada envio: testes (`php tests/run.php`), `php bin/scan-secrets.php` (**só enviar se passar**) e, quando mexer em banco, `bin\pg-teste.ps1` (esquema descartável no Supabase).
3. “Deployment *Ready*” **não** prova funcionamento: conferir `/saude`, o cardápio, o login e um pedido de teste pela URL pública.
4. Segredos (senha do banco, chave privada QZ, tokens) **nunca** vão para o GitHub, logs ou conversa: ficam em variáveis da Vercel (marcadas como secretas) e, localmente, em `storage/` (ignorado pelo Git).
5. Trabalhos de impressão **retidos** só são liberados por ordem explícita do proprietário.
6. A estação (computador com QZ Tray) precisa estar ligada, com a página da estação aberta e iniciada; o QZ nunca é exposto à internet.
