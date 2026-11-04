<form method="post" action="/equipe/convite" class="login">
  <div class="brand-band login-brand"><?= View::brand('login') ?></div>
  <h1 class="login-title">Ativar acesso</h1>
  <?php if ($done): ?>
    <p class="okmsg" role="status">Senha definida e acesso ativado. Entre com o seu e-mail e a senha que você acabou de escolher.</p><p style="text-align:center"><a class="b" href="/equipe">Entrar</a></p>
  <?php elseif (!$ok): ?>
    <p class="err" role="alert">Link inválido, já usado, revogado ou expirado.</p><p class="muted small" style="text-align:center">Peça um novo convite ao proprietário.</p>
  <?php else: ?>
    <p>Olá, <b><?= e($who['name']) ?></b>. Seu perfil: <b><?= e($who['role']) ?></b>. Escolha a sua senha.</p>
    <?php if ($error): ?><p class="err" role="alert"><?= e($error) ?></p><?php endif; ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <label>Senha (mín. 10 caracteres, letras e números)<input name="password" type="password" autocomplete="new-password" required autofocus></label>
    <label>Repita a senha<input name="password2" type="password" autocomplete="new-password" required></label>
    <button class="b">Definir senha e ativar</button>
  <?php endif; ?>
</form>
