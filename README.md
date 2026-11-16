# ContábilFlow

Sistema web de **gestão de guias e documentos contábeis** para escritórios de contabilidade (MVP).
A equipe cadastra as empresas clientes e as obrigações (DAS, DARF, INSS, FGTS, ISS, ICMS, DCTFWeb...),
acompanha os prazos em um **painel com semáforo** e gera, em um clique, o **lembrete pronto** para
enviar ao empresário por WhatsApp, e-mail ou texto copiado.

> **Quem usa o sistema:** apenas a equipe do escritório (perfis *administrador* e *contador*).
> O empresário **não tem login**: ele só recebe os lembretes que o contador envia.

![PHP 8.2](https://img.shields.io/badge/PHP-8.2%2B-777bb4) ![MySQL](https://img.shields.io/badge/MySQL%2FMariaDB-8.0%2F10.4%2B-4479a1) ![Testes](https://img.shields.io/badge/testes-1084%20passando-brightgreen) ![PSR-12](https://img.shields.io/badge/c%C3%B3digo-PSR--12-blue)

---

## Sumário
1. [Funcionalidades](#funcionalidades)
2. [Instalação passo a passo](#instalação-passo-a-passo)
3. [Primeiro acesso e dados de exemplo](#primeiro-acesso-e-dados-de-exemplo)
4. [Como o sistema funciona (regras de negócio)](#como-o-sistema-funciona-regras-de-negócio)
5. [Rotas](#rotas)
6. [Arquitetura e decisões](#arquitetura-e-decisões)
7. [Segurança](#segurança)
8. [Testes e qualidade](#testes-e-qualidade)
9. [Checklist de deploy](#checklist-de-deploy)
10. [Solução de problemas](#solução-de-problemas)
11. [Limitações e próximos passos](#limitações-e-próximos-passos)

---

## Funcionalidades

| Área | O que faz |
|---|---|
| **Login** | E-mail e senha, bloqueio de 15 min após 5 falhas, sessão expira após 30 min parados |
| **Painel** (`/`) | Cartões por cor do semáforo, valor total em aberto, pagas no mês e lista "vencendo esta semana" |
| **Empresas** | Cadastro com CNPJ validado, regime, contatos e contador responsável; busca, filtros e paginação |
| **Guias** | Cadastro por empresa e competência, semáforo de prazos, filtros (cor, tipo, status, período), troca de status |
| **Lembrete rápido** | Mensagem pronta (tom muda com a cor) com link `wa.me`, `mailto:` e botão copiar; cada envio fica no histórico da guia |
| **Usuários** *(admin)* | Cria contadores e administradores, ativa e desativa (a sessão do desativado cai na hora) |
| **Avaliação** | Todos respondem 5 perguntas + NPS; o admin vê médias, NPS por perfil e comentários |

### Perfis

| Perfil | Pode |
|---|---|
| **Contador** | Tudo de empresas, guias, lembretes, painel e enviar avaliação |
| **Administrador** | Tudo do contador **mais** gerenciar usuários e ver o resultado das avaliações |

---

## Instalação passo a passo

### Requisitos
- **PHP 8.2 ou superior** com as extensões `pdo_mysql` e `mbstring`
- **MySQL 8.0.16+ ou MariaDB 10.4+** (versões mínimas em que `CHECK` e colunas geradas funcionam)
- **Composer 2**
- Windows, Linux ou macOS. No Windows, o **XAMPP** traz PHP e MariaDB prontos.

### 1. Baixe o projeto e instale as dependências
```bash
git clone https://github.com/maria-eduardass/contabilflow.git
cd contabilflow
composer install
```
> Sem `composer` no PATH (comum no Windows)? Baixe-o em getcomposer.org e rode `php composer.phar install`.
> Sem `php` no PATH com XAMPP? Use `C:\xampp\php\php.exe` no lugar de `php`.

### 2. Crie os bancos e o usuário do MySQL
Entre no MySQL (`mysql -u root -p`, ou o phpMyAdmin do XAMPP) e rode:
```sql
CREATE DATABASE contabilflow       CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE contabilflow_test  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'contabilflow'@'localhost' IDENTIFIED BY 'troque-esta-senha';
GRANT ALL PRIVILEGES ON contabilflow.*       TO 'contabilflow'@'localhost';
GRANT ALL PRIVILEGES ON contabilflow_test.*  TO 'contabilflow'@'localhost';
FLUSH PRIVILEGES;
```
O banco `contabilflow_test` é **separado** e usado só pelos testes (eles apagam e recriam as tabelas dele).

### 3. Configure o `.env`
```bash
cp .env.example .env        # no Windows: copy .env.example .env
```
Edite o `.env`:

| Variável | Para que serve |
|---|---|
| `APP_ENV` | `local` (desenvolvimento) ou `production` |
| `APP_DEBUG` | `true` mostra detalhes de erro **só em desenvolvimento**; em produção, sempre `false` |
| `APP_TIMEZONE` | Fuso do semáforo e das datas (padrão `America/Sao_Paulo`) |
| `DB_HOST` `DB_PORT` `DB_NAME` | Onde está o banco (`127.0.0.1`, `3306`, `contabilflow`) |
| `DB_USER` `DB_PASS` | Usuário e senha criados no passo 2 |
| `DB_NAME_TEST` | Banco dos testes (`contabilflow_test`) |
| `SEED_ADMIN_PASSWORD` `SEED_CONTADOR_PASSWORD` | Senhas (mín. 10 caracteres) dos usuários de demonstração do `bin/seed.php` |

> O `.env` **nunca vai para o Git** (está no `.gitignore`).

### 4. Crie as tabelas
```bash
php bin/migrate.php
```
Aplica os arquivos de `database/migrations/` em ordem e registra os já aplicados (pode rodar de novo sem problema).

### 5. Carregue dados de demonstração (opcional, só em desenvolvimento)
Escolha **uma** das opções (detalhes na próxima seção):
```bash
php bin/seed.php                                                    # opção A: usa as senhas do .env
mysql -u contabilflow -p contabilflow < database/dados_exemplo.sql  # opção B: script SQL pronto
```

### 6. Suba o servidor e abra o site
```bash
php -S localhost:8000 -t public
```
Acesse **http://localhost:8000** e entre com um dos usuários abaixo.

---

## Primeiro acesso e dados de exemplo

### Opção A: `php bin/seed.php`
Cria 2 usuários, 3 empresas e 9 guias (uma em cada faixa do semáforo, com datas relativas a hoje).
As senhas são as do `.env`. O seed **se recusa a rodar** com `APP_ENV=production` ou se já houver usuários.

| E-mail | Perfil | Senha |
|---|---|---|
| `admin@contabilflow.local` | Administrador | `SEED_ADMIN_PASSWORD` |
| `contador@contabilflow.local` | Contador | `SEED_CONTADOR_PASSWORD` |

### Opção B: `database/dados_exemplo.sql`
Script SQL completo e **repetível** (apaga só os próprios dados de exemplo antes de recriar):
3 usuários, 5 empresas, 14 guias, 3 lembretes e 4 avaliações. Todas as datas são relativas a `CURDATE()`,
então **sempre há exemplo de todas as cores**, em qualquer dia em que for importado.
No XAMPP, também dá para importar pelo phpMyAdmin (banco `contabilflow` → *Importar*).

| E-mail | Perfil | Senha |
|---|---|---|
| `admin@demo.contabilflow.local` | Administrador | `Demo@Contabil2026` |
| `contador@demo.contabilflow.local` | Contador | `Demo@Contabil2026` |
| `estagio@demo.contabilflow.local` | Contador **inativo** | `Demo@Contabil2026` (não consegue entrar) |

> ⚠️ A senha do script é pública. **Nunca use dados de exemplo em produção.**

---

## Como o sistema funciona (regras de negócio)

### Semáforo de prazos
Calculado no servidor, em **dias de calendário** (data sem hora), com a data do dia vinda de um `Clock`:

| Cor | Quando | Significado |
|---|---|---|
| ⚫ Preto | vencimento já passou | Vencida |
| 🔴 Vermelho | vence hoje, amanhã ou em 2 dias | Vence em até 2 dias |
| 🟡 Amarelo | vence em 3 a 7 dias | Vence em 3 a 7 dias |
| 🟢 Verde | vence em mais de 7 dias | No prazo |
| ⚪ Cinza | guia paga ou cancelada | Sem prazo |

Guia **enviada** ainda está em aberto: o prazo vale até pagar. O painel, a lista e os filtros usam as mesmas
faixas (`SemaforoService`), então os números nunca discordam entre si.

### Status de uma guia
```
pendente ──> enviada ──> paga          paga é definitivo (não edita, não exclui)
    │            └─────> cancelada ──> pendente (reabrir)
    ├──────────────────> paga
    └──────────────────> cancelada
```
As transições são checadas **dentro do `UPDATE`** (atômico). Duas pessoas mexendo na mesma guia: a segunda recebe **409** com o estado atual.

### Regras de cadastro
- **Uma guia ativa por empresa, tipo e competência.** O tipo `OUTRA` (exige descrição) e as guias canceladas ficam fora da regra.
- Competência no formato `AAAA-MM` (gravada no dia 01); o vencimento não pode ser anterior a ela.
- Valor com até 2 casas decimais, digitado como `1234,56` (sem separador de milhar, que seria ambíguo).
- **CNPJ** validado pelos dígitos verificadores e salvo só com números; **WhatsApp** salvo como `55` + DDD + número.
- Empresa com guias não pode ser excluída (o sistema sugere **desativar**); empresa inativa não recebe guias novas.
- Edição de empresa e guia usa **versão** (lock otimista): quem salvar com versão antiga vê o que mudou antes de continuar.

### Lembrete rápido
A mensagem muda de tom com a cor (vencida com dias de atraso, "vence HOJE", "vence em N dias", lembrete amigável).
O envio é **manual**: o link `wa.me` (gratuito, sem API paga) abre a conversa com o texto pronto; o `mailto:` abre o
e-mail. O contador pode editar o texto antes de enviar, e cada envio é registrado no histórico da guia (quem, quando, por qual canal).
Sem WhatsApp ou e-mail cadastrado na empresa, o botão correspondente fica desabilitado. Guia paga ou cancelada não gera lembrete.

### Avaliação de usabilidade
5 perguntas (facilidade, clareza do semáforo, utilidade do lembrete, rapidez, "substituiria minha planilha/WhatsApp")
na escala de 1 a 5, mais a nota de recomendação de 0 a 10. **NPS** = % de promotores (9-10) − % de detratores (0-6),
de −100 a 100, com as faixas padrão (crítica, aperfeiçoamento, qualidade, excelência). Perfil e autor vêm da sessão, nunca do formulário.

---

## Rotas

Todas exigem login, exceto `GET/POST /login`. Toda requisição que altera dados exige o token CSRF (resposta **419** sem ele).
Formulários HTML usam `_method` (PUT, PATCH, DELETE) dentro de um POST.

| Método | Rota | Acesso | O que faz |
|---|---|---|---|
| GET | `/` | todos | Painel (`/dashboard` redireciona para cá) |
| GET | `/api/dashboard/indicadores` | todos | Indicadores em JSON |
| GET, POST | `/login` | público | Formulário e autenticação |
| POST | `/logout` | todos | Encerra a sessão |
| GET | `/empresas` | todos | Lista com busca, filtros e paginação |
| GET | `/empresas/nova` · POST `/empresas` | todos | Cadastro |
| GET | `/empresas/{id}` | todos | Detalhe e guias da empresa |
| GET | `/empresas/{id}/editar` · PUT `/empresas/{id}` | todos | Edição (com `versao`) |
| DELETE | `/empresas/{id}` | todos | Exclusão (409 se tiver guias) |
| GET | `/obrigacoes` | todos | Lista: `q`, `empresa_id`, `tipo`, `status`, `semaforo`, `de`, `ate`, `sort`, `page` |
| GET | `/obrigacoes/nova` · POST `/obrigacoes` | todos | Cadastro |
| GET | `/obrigacoes/{id}` | todos | Detalhe, semáforo, ações e histórico de lembretes |
| GET | `/obrigacoes/{id}/editar` · PUT `/obrigacoes/{id}` | todos | Edição (guia paga não edita) |
| PATCH | `/obrigacoes/{id}/status` | todos | Troca de status (`status`, `versao`) |
| DELETE | `/obrigacoes/{id}` | todos | Exclusão (guia paga: 409) |
| GET | `/api/obrigacoes/{id}/lembrete` | todos | Prévia: `{mensagem, whatsappUrl, mailtoUrl, semaforo}` |
| POST | `/api/obrigacoes/{id}/lembretes` | todos | Registra o envio (`canal`, `mensagem`), responde 201 |
| GET | `/avaliacao` · POST `/avaliacao` | todos | Formulário e envio da avaliação |
| GET | `/usuarios` · `/usuarios/novo` · POST `/usuarios` | **admin** | Lista e cadastro de usuários |
| PATCH | `/usuarios/{id}/ativo` | **admin** | Ativa ou desativa (`ativo` = 0 ou 1) |
| GET | `/avaliacoes/resumo` | **admin** | Médias, NPS e comentários |

**Códigos de resposta:** `302` redireciona (sucesso de formulário ou falta de login) · `401` sem sessão (JSON) ·
`403` perfil sem permissão · `404` não encontrado · `405` método não permitido (com `Allow`) ·
`409` conflito (duplicidade, vínculo ou alterado por outra pessoa) · `419` token CSRF ausente ou inválido ·
`422` dado inválido (a tela volta com os erros e o que foi digitado) · `500` erro genérico (detalhes só no log).
Erros de API vêm como `{"error": {"code", "message", "fields"?}}`.

---

## Arquitetura e decisões

Sem framework, de propósito: o MVP é pequeno e o objetivo é código fácil de ler, testar e auditar. O Composer só
cuida do autoload (PSR-4) e das ferramentas de desenvolvimento.

```
contabilflow/
├─ public/            único ponto de entrada (index.php) e assets (Bootstrap local, app.js, app.css)
├─ config/routes.php  todas as rotas num só lugar
├─ src/
│  ├─ App.php         raiz de composição: monta tudo (usada pelo site E pelos testes HTTP)
│  ├─ Core/           Router, Request/Response, Kernel, Session, Csrf, middlewares, View, Database, Migrator
│  ├─ Controllers/    finos: validam, chamam serviço ou repositório e escolhem a resposta
│  ├─ Services/       regra de negócio pura (Semáforo, Lembrete, Dashboard, Auth)
│  ├─ Models/         entidades, DTOs e repositórios (todo o SQL fica aqui)
│  └─ Validation/     Validator por campo, regras de CNPJ e WhatsApp
├─ views/             templates PHP (layout + telas), toda saída escapada com e()
├─ database/          migrations/*.sql e dados_exemplo.sql
├─ bin/               migrate.php e seed.php
├─ storage/logs/      app.log (fora do alcance do navegador)
└─ tests/             Unit/, Integration/ (+ Http/ ponta a ponta), Support/
```

### Fluxo de uma requisição
`public/index.php` → `App::build()` → **Kernel** → middlewares na ordem **Sessão → CSRF → Autenticação → Perfil** → **Router** → Controller → Service/Repository → View.
Os cabeçalhos de segurança são aplicados por fora de tudo, inclusive em redirecionamentos e erros.

### Decisões e o porquê
| Decisão | Por quê |
|---|---|
| **SQL só nos repositórios**, sempre com *prepared statements* reais (`EMULATE_PREPARES=false`) | O valor nunca é interpolado no SQL: defesa real contra SQL Injection |
| **Modo SQL estrito** ligado em toda conexão | Sem ele o MariaDB do XAMPP grava valor fora do `ENUM` ou texto longo demais de forma silenciosa |
| **Unicidade e vínculos garantidos pelo banco** (`UNIQUE`, `FOREIGN KEY`); o PHP só traduz o erro (1062 → 409, 1451 → 409) | "SELECT antes do INSERT" tem condição de corrida; o banco não |
| **Coluna gerada `dedup_key`** para "uma guia ativa por empresa/tipo/competência" | Vale mesmo com requisições simultâneas; `NULL` libera `OUTRA` e as canceladas |
| **Lock otimista** (`WHERE versao = ?`) | Duas pessoas editando a mesma linha não se sobrescrevem em silêncio |
| **Transições de status dentro do `UPDATE`** | Atômicas: não existe "ler e depois gravar" |
| **Semáforo como função pura de data + `Clock` injetável** | Testes determinísticos; a cor na tela e o filtro no SQL usam as mesmas constantes |
| **Uma consulta agregada para o painel** (`SUM/CASE`) | O painel não faz uma consulta por indicador |
| **Validação no servidor com whitelist de campos** | Campo extra no POST (`perfil`, `id`, `status`) nunca chega ao banco (*mass assignment*) |
| **Senha crua, sem `trim`**, e só como hash (`password_hash`) | O login compara o que foi digitado; aparar espaços criaria outra senha |
| **Bootstrap servido localmente e CSP restrita** | Sem CDN e sem script/estilo inline, um XSS que escape do filtro tem pouco poder |
| **Lembrete por link `wa.me`** e não pela API do WhatsApp | Gratuito, sem aprovação, e o contador mantém o controle do que sai |
| **Data e hora vêm do `Clock`**, não do banco | Fuso único (`APP_TIMEZONE`) e relógio controlável nos testes |

---

## Segurança

- **Senhas:** `password_hash` (bcrypt), nunca em texto; reaplicado o *hash* quando o algoritmo evolui.
- **Login:** mensagem genérica para e-mail inexistente, senha errada e conta inativa (não revela quais e-mails existem); tempo de resposta igualado; bloqueio por conta após 5 falhas (15 min). *Trade-off conhecido:* o aviso de "conta bloqueada" só aparece para e-mails reais, o que revela a existência dessas contas (aceito por exigência do requisito).
- **Sessão:** cookie `HttpOnly`, `SameSite=Lax`, `Secure` em HTTPS, `use_strict_mode`; ID e token CSRF renovados no login e no logout; expira após 30 min de inatividade; **desativar um usuário derruba a sessão dele imediatamente**.
- **CSRF:** token por sessão comparado com `hash_equals`, exigido em todo método que altera dados (inclusive PUT/PATCH/DELETE via `_method`), aceito em campo oculto ou no cabeçalho `X-CSRF-Token`.
- **XSS:** toda saída passa por `e()`; sem scripts, estilos ou eventos inline (a CSP proíbe).
- **Cabeçalhos:** `Content-Security-Policy` (`default-src 'self'`, `frame-ancestors 'none'`), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`.
- **Autorização:** negar por padrão (toda rota exige login, exceto `/login`); rotas de admin devolvem 403 ao contador; perfil e autor das ações vêm sempre da sessão.
- **Entrada:** UTF-8 inválido, arrays no lugar de texto, números enormes e *payloads* de SQL são tratados (422/404), nunca 500.
- **Erros:** o usuário vê uma página genérica; o detalhe (sem senhas nem tokens) vai só para `storage/logs/app.log`.
- **Arquivos:** só `public/` fica acessível; `src/`, `views/`, `vendor/` e `.env` ficam fora do alcance do navegador.

---

## Testes e qualidade

```bash
composer test     # PHPUnit: 1084 testes, ~4.000 asserções, cerca de 1 minuto
composer lint     # PHP_CodeSniffer (PSR-12), sem avisos
composer stan     # PHPStan nível 8
```
Os testes de integração usam o banco `DB_NAME_TEST` (nunca o principal) e são pulados se não houver `.env`.

| Camada | O que cobre |
|---|---|
| `tests/Unit` (546) | Validator, CNPJ/WhatsApp, semáforo (limites −1, 0, 2, 3, 7, 8 dias), lembrete, NPS, router, CSRF, sessão, middlewares e controllers com repositórios em memória |
| `tests/Integration` (136) | Repositórios contra o MySQL real: `UNIQUE`, `FOREIGN KEY`, `CHECK`, lock otimista, filtros, paginação, consultas agregadas |
| `tests/Integration/Http` (402) | **Ponta a ponta:** requisições ao Kernel real com banco real, sessão e CSRF de verdade |

### Bateria HTTP (no lugar do E2E com navegador)
O plano previa testes de navegador com Playwright, que exige Node.js. Em vez disso, **402 testes HTTP em PHP** percorrem
o sistema como um usuário faria, usando exatamente a mesma montagem do site (`ContabilFlow\App`):

- **`RouteSecurityTest`** varre **todas as rotas**: o catálogo de rotas do teste é comparado com o do `Router`
  (rota nova sem teste quebra a suíte), e cada uma é verificada quanto a login, CSRF, perfil e cabeçalhos de segurança.
- **Fluxos completos:** login e bloqueio de conta, painel, empresas, guias (filtros e transições), lembretes, usuários, avaliação.
- **`UserJourneyTest`** reproduz os 7 cenários do plano e um dia inteiro de trabalho, do login ao logout.
- **`HardeningTest`** testa erro 500 sem vazamento, entradas malformadas e *payloads* de SQL Injection.
- **`ExampleSqlTest`** executa o `dados_exemplo.sql` de verdade e confere o resultado.
- Qualquer erro registrado no log durante um teste o faz falhar (nenhum 500 passa despercebido).

### Cobertura de código
Não há PCOV nem Xdebug instalados por padrão. Para medir: instale a extensão `pcov` e rode
`php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text`. A meta do plano é ≥ 80% em `Services`, `Validation` e `Models`.

---

## Checklist de deploy

- [ ] Servidor web com **document root em `public/`** (nunca na raiz do projeto)
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] `.env` de produção: `APP_ENV=production`, **`APP_DEBUG=false`**, `APP_TIMEZONE` correto, senha forte do banco
- [ ] Usuário do MySQL **só com acesso ao banco do sistema** (`GRANT ... ON contabilflow.*`), nunca `ALL ON *.*`
- [ ] **HTTPS obrigatório.** O cookie só recebe `Secure` quando `$_SERVER['HTTPS']` está ligado; atrás de proxy reverso, configure o servidor para repassar isso
- [ ] `php bin/migrate.php` aplicado; **não** rode o `seed.php` nem o `dados_exemplo.sql` (o seed já se recusa em produção)
- [ ] Crie o **primeiro administrador** com senha própria (o seed é bloqueado em produção):
  ```bash
  php -r "echo password_hash('SUA-SENHA-FORTE-AQUI', PASSWORD_DEFAULT), PHP_EOL;"
  ```
  ```sql
  INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES ('Seu Nome', 'voce@escritorio.com', '<hash acima>', 'admin');
  ```
  Depois cadastre a equipe pela tela **Usuários**.
- [ ] `storage/logs/` gravável pelo servidor web e **fora** do alcance público; configure rotação de logs
- [ ] PHP com `display_errors=Off` e `expose_php=Off`
- [ ] **Backup diário do banco** testado (a restauração também)
- [ ] Revise a senha de **todos** os usuários de demonstração: nenhum pode existir em produção
- [ ] Atualize PHP e dependências com regularidade (`composer outdated`)

---

## Solução de problemas

| Sintoma | Causa provável e solução |
|---|---|
| `php` ou `composer` "não é reconhecido" | Não estão no PATH. No XAMPP use `C:\xampp\php\php.exe`; instale o Composer |
| `Access denied for user 'contabilflow'` | Usuário ou senha do `.env` errados, ou o usuário perdeu as permissões. Refaça os `GRANT` do passo 2 |
| `Access denied ... to database` logo depois de o XAMPP fechar de forma abrupta | A tabela de sistema `mysql.db` pode ter corrompido (erro *"Read page with wrong checksum"*). Como `root`: `REPAIR TABLE mysql.db;` e refaça os `GRANT`. Faça backup da pasta `xampp/mysql/data/mysql` antes |
| Páginas dão erro ou *"conexão recusada"* | O MySQL do XAMPP caiu. Ligue-o no painel do XAMPP; feche sempre pelo botão **Stop**, não encerrando a janela |
| Tabelas não existem | Rode `php bin/migrate.php` |
| `Seed bloqueado` ou `Seed já aplicado` | O seed só roda com `APP_ENV` diferente de `production` e com a tabela `usuarios` vazia |
| Valor/texto recusado com erro de banco | O modo estrito está ligado por desenho. Dado inválido vira erro 422 na tela, não gravação silenciosa |
| Porta 8000 ocupada | Use outra: `php -S localhost:8080 -t public` |
| Testes pulados (*skipped*) | Falta o `.env` com `DB_NAME_TEST` e permissões no banco de testes |
| Botão "Abrir WhatsApp" ou "Abrir e-mail" desabilitado no lembrete | A empresa não tem esse contato cadastrado. Edite a empresa e informe o WhatsApp (DDD + número) ou o e-mail |

---

## Limitações e próximos passos

Fora do escopo do MVP, por decisão do plano:
- **Anexo do PDF da guia** (a tabela já comporta incluir depois)
- **Login do empresário** (hoje ele só recebe o lembrete)
- **Envio automático** de WhatsApp/e-mail (hoje o contador abre o link e envia)
- **Recuperação de senha** por e-mail e autenticação em dois fatores
- **Trilha de auditoria** de quem alterou o quê, relatórios e exportação para planilha

Pendências técnicas conhecidas:
- **Teste E2E com navegador (Playwright):** exige Node.js; hoje coberto pela bateria HTTP em PHP
- **Medição de cobertura:** requer instalar o PCOV (ver *Testes e qualidade*)
- O seletor de empresas dos formulários lista todas as empresas; com milhares, vale trocar por busca
- 6 apontamentos antigos de tipagem do PHPStan em testes de integração de tarefas iniciais

## Histórico do projeto

O projeto foi construído em 20 tarefas, cada uma com testes e *commit* próprios:
estrutura, banco e migrações, roteador, views, sessão e CSRF, validador, autenticação, autorização,
empresas (repositório e telas), guias (repositório e telas), semáforo, painel, lembrete rápido,
usuários, avaliação, testes de ponta a ponta (em PHP) e esta documentação.
