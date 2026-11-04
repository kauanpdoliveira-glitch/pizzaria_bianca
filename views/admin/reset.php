<form method="post" action="/equipe/redefinir" class="login">
  <div class="brand-band login-brand"><?= View::brand('login') ?></div>
  <h1 class="login-title">Nova senha</h1>
  <?php if ($done): ?>
    <p class="okmsg" role="status">Senha redefinida. Todas as sessões antigas foram encerradas.</p><p style="text-align:center"><a class="b" href="/equipe">Entrar</a></p>
  <?php elseif (!$ok): ?>
    <p class="err" role="alert">Link inválido, já usado ou expirado.</p><p style="text-align:center"><a href="/equipe/recuperar">Pedir um novo link</a></p>
  <?php else: ?>
    <?php if ($error): ?><p class="err" role="alert"><?= e($error) ?></p><?php endif; ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <label>Nova senha (mín. 10 caracteres, letras e números)<input name="password" type="password" autocomplete="new-password" required autofocus></label>
    <label>Repita a nova senha<input name="password2" type="password" autocomplete="new-password" required></label>
    <button class="b">Salvar nova senha</button>
  <?php endif; ?>
</form>
