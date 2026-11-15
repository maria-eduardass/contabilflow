# ContábilFlow

Sistema de gestão de guias e documentos contábeis para escritórios de contabilidade (MVP).
Cadastro de empresas e obrigações, painel com semáforo de prazos e gerador de lembrete
rápido para o cliente. Apenas a equipe do escritório acessa o sistema.

## Requisitos
- PHP >= 8.2 (extensões `pdo_mysql`, `mbstring`)
- MySQL 8.0.16+ ou MariaDB 10.4+
- Composer 2

## Setup
```bash
composer install
cp .env.example .env     # ajuste as credenciais do banco
php bin/migrate.php      # cria as tabelas (idempotente)
php bin/seed.php         # dados de demonstração (só com APP_ENV != production)
php -S localhost:8000 -t public
```

## Qualidade
```bash
composer test    # PHPUnit
composer lint    # PHP_CodeSniffer (PSR-12)
composer stan    # PHPStan nível 8
```

## Estrutura
`public/` (único ponto de entrada) · `src/` (Core, Controllers, Models, Services) ·
`views/` · `database/` · `tests/` · `storage/logs/`
