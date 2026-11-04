<form method="post" action="/equipe" class="login" autocomplete="on">
  <div class="brand-band login-brand"><?= View::brand('login') ?></div>
  <h1 class="login-title">Área da equipe</h1><p class="muted login-sub">Acesso restrito a funcionários cadastrados pelo proprietário.</p>
  <?php if ($error): ?><p class="err" role="alert"><?= e($error) ?></p><?php endif; ?>
  <label>E-mail<input name="email" type="email" autocomplete="username" required autofocus></label>
  <label>Senha<input name="password" type="password" autocomplete="current-password" required></label>
  <button class="b">Entrar</button>
  <p class="muted small" style="text-align:center;margin-top:14px"><a href="/equipe/recuperar">Esqueci minha senha</a></p>
  <p style="text-align:center"><a href="/">← Voltar ao site</a></p>
</form>
