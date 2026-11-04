<form method="post" action="/equipe/recuperar" class="login">
  <div class="brand-band login-brand"><?= View::brand('login') ?></div>
  <h1 class="login-title">Recuperar senha</h1>
  <?php if ($sent): ?>
    <p class="okmsg" role="status">Se o e-mail estiver cadastrado e ativo, enviamos um link para redefinir a senha. Ele vale por 60 minutos e só pode ser usado uma vez.</p>
    <p style="text-align:center"><a href="/equipe">← Voltar ao login</a></p>
  <?php else: ?>
    <p class="muted login-sub">Informe o e-mail do seu cadastro. Enviaremos um link para criar uma nova senha. Isso não altera seu cargo nem permissões.</p>
    <label>E-mail<input name="email" type="email" autocomplete="email" required autofocus></label>
    <button class="b">Enviar link</button>
    <p style="text-align:center"><a href="/equipe">← Voltar ao login</a></p>
  <?php endif; ?>
</form>
