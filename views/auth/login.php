<div class="row justify-content-center">
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h4 mb-3">Entrar</h1>
                <?php if ($message !== null): ?>
                    <div class="alert alert-danger" role="alert"><?= e($message) ?></div>
                <?php endif; ?>
                <form method="post" action="/login" novalidate>
                    <?= $csrfField ?>
                    <div class="mb-3">
                        <label for="email" class="form-label">E-mail</label>
                        <input type="email" id="email" name="email" value="<?= e($email) ?>"
                               class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
                               autocomplete="username" autofocus required>
                        <?php if (isset($errors['email'])): ?>
                            <div class="invalid-feedback"><?= e($errors['email']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="senha" class="form-label">Senha</label>
                        <input type="password" id="senha" name="senha"
                               class="form-control<?= isset($errors['senha']) ? ' is-invalid' : '' ?>"
                               autocomplete="current-password" required>
                        <?php if (isset($errors['senha'])): ?>
                            <div class="invalid-feedback"><?= e($errors['senha']) ?></div>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Entrar</button>
                </form>
            </div>
        </div>
    </div>
</div>
