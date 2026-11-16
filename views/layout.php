<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(isset($title) ? $title . ' · ContábilFlow' : 'ContábilFlow') ?></title>
    <link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand navbar-dark bg-primary mb-4">
        <div class="container">
            <a class="navbar-brand" href="/">ContábilFlow</a>
            <?php if (!empty($userName)): ?>
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link" href="/">Painel</a></li>
                    <li class="nav-item"><a class="nav-link" href="/empresas">Empresas</a></li>
                    <li class="nav-item"><a class="nav-link" href="/obrigacoes">Guias</a></li>
                    <?php if (!empty($isAdmin)): ?>
                        <li class="nav-item"><a class="nav-link" href="/usuarios">Usuários</a></li>
                    <?php endif; ?>
                </ul>
                <form method="post" action="/logout" class="d-flex align-items-center gap-2">
                    <?= $csrfField ?? '' ?>
                    <span class="navbar-text"><?= e($userName) ?></span>
                    <button type="submit" class="btn btn-outline-light btn-sm">Sair</button>
                </form>
            <?php endif; ?>
        </div>
    </nav>
    <main class="container pb-5">
        <?php foreach ($flash ?? [] as $item): ?>
            <div class="alert alert-<?= e($item['type']) ?>" role="alert"><?= e($item['message']) ?></div>
        <?php endforeach; ?>
        <?= $content ?>
    </main>
    <script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/app.js"></script>
</body>
</html>
