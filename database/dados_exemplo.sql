-- ============================================================================
-- ContábilFlow - dados de exemplo para demonstração e testes manuais
-- ============================================================================
-- COMO USAR
--   1. Crie as tabelas antes:            php bin/migrate.php
--   2. Importe este arquivo:
--        mysql -u contabilflow -p contabilflow < database/dados_exemplo.sql
--      (ou, no XAMPP: phpMyAdmin > banco "contabilflow" > Importar)
--   3. Entre em http://localhost:8000/login com um usuário abaixo.
--
-- USUÁRIOS DE DEMONSTRAÇÃO (todos com a mesma senha: Demo@Contabil2026)
--   admin@demo.contabilflow.local      administrador  (gerencia usuários e vê as avaliações)
--   contador@demo.contabilflow.local   contador
--   estagio@demo.contabilflow.local    contador INATIVO (serve para testar o bloqueio de acesso)
--
-- ATENÇÃO: esta senha é pública (está no repositório). Use este script SOMENTE em
-- desenvolvimento/demonstração, NUNCA em produção. Em produção, crie o primeiro
-- administrador com uma senha própria e forte.
--
-- AS DATAS SÃO RELATIVAS A "HOJE" (CURDATE()): ao importar, o semáforo sempre terá
-- exemplos de todas as cores (preto, vermelho, amarelo, verde e cinza), em qualquer dia.
--
-- O SCRIPT PODE SER EXECUTADO MAIS DE UMA VEZ: ele apaga apenas os dados de exemplo
-- anteriores (usuários @demo.contabilflow.local e as 5 empresas de exemplo) e recria.
-- Não toca em nenhum outro dado do banco.
-- ============================================================================

SET NAMES utf8mb4;
SET @senha_demo = '$2y$10$9H7dBTmVRT1v4LlkO7tuG.u7K048IUXTXcLockh./oLFVbY3RDFM2';

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. Limpeza dos dados de exemplo anteriores (ordem respeita as chaves estrangeiras)
-- ---------------------------------------------------------------------------
DELETE FROM lembretes WHERE obrigacao_id IN (
  SELECT id FROM obrigacoes WHERE empresa_id IN (
    SELECT id FROM empresas WHERE cnpj IN
      ('11222333000181', '11444777000161', '45723174000110', '10000000000145', '10000000000226')
  )
);
DELETE FROM avaliacoes WHERE usuario_id IN (SELECT id FROM usuarios WHERE email LIKE '%@demo.contabilflow.local');
DELETE FROM obrigacoes WHERE empresa_id IN (
  SELECT id FROM empresas WHERE cnpj IN
    ('11222333000181', '11444777000161', '45723174000110', '10000000000145', '10000000000226')
);
DELETE FROM empresas WHERE cnpj IN
  ('11222333000181', '11444777000161', '45723174000110', '10000000000145', '10000000000226');
DELETE FROM usuarios WHERE email LIKE '%@demo.contabilflow.local';

-- ---------------------------------------------------------------------------
-- 2. Usuários
-- ---------------------------------------------------------------------------
INSERT INTO usuarios (nome, email, senha_hash, perfil, ativo) VALUES
  ('Administrador Demo', 'admin@demo.contabilflow.local',    @senha_demo, 'admin',    1),
  ('Contador Demo',      'contador@demo.contabilflow.local', @senha_demo, 'contador', 1),
  ('Estagiário Inativo', 'estagio@demo.contabilflow.local',  @senha_demo, 'contador', 0);

SET @admin    = (SELECT id FROM usuarios WHERE email = 'admin@demo.contabilflow.local');
SET @contador = (SELECT id FROM usuarios WHERE email = 'contador@demo.contabilflow.local');

-- ---------------------------------------------------------------------------
-- 3. Empresas (CNPJs válidos de exemplo; a 5ª está inativa e a 3ª não tem contatos)
-- ---------------------------------------------------------------------------
INSERT INTO empresas (razao_social, nome_fantasia, cnpj, regime, email, whatsapp, responsavel_id, ativo) VALUES
  ('Padaria Pão Quente Ltda',   'Pão Quente',     '11222333000181', 'simples',   'contato@paoquente.example', '5511987654321', @contador, 1),
  ('Oficina Mecânica Silva ME', NULL,             '11444777000161', 'mei',       'silva@oficina.example',     '5521998765432', @contador, 1),
  ('Tech Soluções S.A.',        'TechSol',        '45723174000110', 'presumido', NULL,                        NULL,            @admin,    1),
  ('Mercado Boa Vista Ltda',    'Boa Vista',      '10000000000145', 'real',      'financeiro@boavista.example', '5531991234567', @contador, 1),
  ('Confecções Aurora ME',      'Aurora Moda',    '10000000000226', 'simples',   'aurora@moda.example',       '5541988887777', @contador, 0);

SET @padaria  = (SELECT id FROM empresas WHERE cnpj = '11222333000181');
SET @oficina  = (SELECT id FROM empresas WHERE cnpj = '11444777000161');
SET @tech     = (SELECT id FROM empresas WHERE cnpj = '45723174000110');
SET @mercado  = (SELECT id FROM empresas WHERE cnpj = '10000000000145');
SET @aurora   = (SELECT id FROM empresas WHERE cnpj = '10000000000226');

-- ---------------------------------------------------------------------------
-- 4. Guias (obrigações), com vencimentos relativos a hoje
--    competência = sempre o dia 01 do mês anterior ao vencimento
--    Cores esperadas: -5/-12 preto, 0/+1/+2 vermelho, +4/+7 amarelo, +10/+25/+40 verde, paga/cancelada cinza
-- ---------------------------------------------------------------------------
INSERT INTO obrigacoes (empresa_id, tipo, descricao, competencia, vencimento, valor, status, enviada_em, paga_em, observacoes, criado_por) VALUES
  -- Padaria Pão Quente
  (@padaria, 'FGTS',    NULL, DATE_FORMAT(DATE_SUB(DATE_SUB(CURDATE(), INTERVAL 5 DAY),  INTERVAL 1 MONTH), '%Y-%m-01'), DATE_SUB(CURDATE(), INTERVAL 5 DAY),  412.30, 'pendente',  NULL, NULL, 'Cliente avisado por telefone, aguardando o comprovante.', @contador),
  (@padaria, 'DAS',     NULL, DATE_FORMAT(DATE_SUB(DATE_ADD(CURDATE(), INTERVAL 1 DAY),  INTERVAL 1 MONTH), '%Y-%m-01'), DATE_ADD(CURDATE(), INTERVAL 1 DAY),  1850.75, 'pendente', NULL, NULL, NULL, @contador),
  (@padaria, 'INSS',    NULL, DATE_FORMAT(DATE_SUB(DATE_ADD(CURDATE(), INTERVAL 25 DAY), INTERVAL 1 MONTH), '%Y-%m-01'), DATE_ADD(CURDATE(), INTERVAL 25 DAY), 960.00, 'pendente',  NULL, NULL, NULL, @admin),
  (@padaria, 'ISS',     NULL, DATE_FORMAT(DATE_SUB(DATE_SUB(CURDATE(), INTERVAL 40 DAY), INTERVAL 1 MONTH), '%Y-%m-01'), DATE_SUB(CURDATE(), INTERVAL 40 DAY), 310.00, 'paga',      DATE_SUB(NOW(), INTERVAL 45 DAY), DATE_SUB(NOW(), INTERVAL 41 DAY), NULL, @contador),
  -- Oficina Mecânica Silva
  (@oficina, 'DAS',     NULL, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01'), CURDATE(),                          71.60, 'enviada',  DATE_SUB(NOW(), INTERVAL 3 DAY), NULL, 'DAS MEI do mês.', @contador),
  (@oficina, 'DARF',    NULL, DATE_FORMAT(DATE_SUB(DATE_ADD(CURDATE(), INTERVAL 4 DAY),  INTERVAL 1 MONTH), '%Y-%m-01'), DATE_ADD(CURDATE(), INTERVAL 4 DAY),  245.90, 'pendente', NULL, NULL, NULL, @contador),
  (@oficina, 'ICMS',    NULL, DATE_FORMAT(DATE_SUB(DATE_SUB(CURDATE(), INTERVAL 12 DAY), INTERVAL 1 MONTH), '%Y-%m-01'), DATE_SUB(CURDATE(), INTERVAL 12 DAY), 530.40, 'paga',      DATE_SUB(NOW(), INTERVAL 15 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), 'Pago via PIX.', @contador),
  -- Tech Soluções (sem WhatsApp nem e-mail: o lembrete só gera o texto para copiar)
  (@tech,    'DCTFWEB', NULL, DATE_FORMAT(DATE_SUB(DATE_ADD(CURDATE(), INTERVAL 2 DAY),  INTERVAL 1 MONTH), '%Y-%m-01'), DATE_ADD(CURDATE(), INTERVAL 2 DAY),  0.00, 'cancelada', NULL, NULL, 'Cancelada: empresa sem movimento no período.', @admin),
  (@tech,    'DAS',     NULL, DATE_FORMAT(DATE_SUB(DATE_ADD(CURDATE(), INTERVAL 40 DAY), INTERVAL 1 MONTH), '%Y-%m-01'), DATE_ADD(CURDATE(), INTERVAL 40 DAY), 3120.00, 'pendente', NULL, NULL, NULL, @admin),
  (@tech,    'OUTRA',   'Parcelamento de débitos federais', DATE_FORMAT(DATE_SUB(DATE_ADD(CURDATE(), INTERVAL 7 DAY), INTERVAL 1 MONTH), '%Y-%m-01'), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 1480.20, 'pendente', NULL, NULL, 'Parcela 14 de 60.', @admin),
  -- Mercado Boa Vista
  (@mercado, 'ICMS',    NULL, DATE_FORMAT(DATE_SUB(DATE_SUB(CURDATE(), INTERVAL 12 DAY), INTERVAL 1 MONTH), '%Y-%m-01'), DATE_SUB(CURDATE(), INTERVAL 12 DAY), 2790.10, 'pendente', NULL, NULL, 'Vencida: cliente pediu segunda via da guia.', @contador),
  (@mercado, 'DARF',    NULL, DATE_FORMAT(DATE_SUB(DATE_ADD(CURDATE(), INTERVAL 10 DAY), INTERVAL 1 MONTH), '%Y-%m-01'), DATE_ADD(CURDATE(), INTERVAL 10 DAY), 1325.00, 'pendente', NULL, NULL, NULL, @contador),
  (@mercado, 'FGTS',    NULL, DATE_FORMAT(DATE_SUB(DATE_ADD(CURDATE(), INTERVAL 2 DAY),  INTERVAL 1 MONTH), '%Y-%m-01'), DATE_ADD(CURDATE(), INTERVAL 2 DAY),  1890.45, 'enviada', DATE_SUB(NOW(), INTERVAL 1 DAY), NULL, NULL, @contador),
  -- Confecções Aurora (empresa inativa, mas com histórico)
  (@aurora,  'DAS',     NULL, DATE_FORMAT(DATE_SUB(DATE_SUB(CURDATE(), INTERVAL 30 DAY), INTERVAL 1 MONTH), '%Y-%m-01'), DATE_SUB(CURDATE(), INTERVAL 30 DAY), 640.00, 'paga',      DATE_SUB(NOW(), INTERVAL 33 DAY), DATE_SUB(NOW(), INTERVAL 31 DAY), 'Empresa encerrou as atividades.', @contador);

-- ---------------------------------------------------------------------------
-- 5. Histórico de lembretes enviados
-- ---------------------------------------------------------------------------
INSERT INTO lembretes (obrigacao_id, usuario_id, canal, mensagem, criado_em)
SELECT o.id, @contador, 'whatsapp',
       CONCAT('Olá, Pão Quente!\n\nIdentificamos que a guia abaixo está vencida. Vamos regularizar o quanto antes?\n\nGuia: FGTS\nValor: R$ 412,30\n\nQualquer dúvida, é só falar. Obrigado!'),
       DATE_SUB(NOW(), INTERVAL 2 DAY)
FROM obrigacoes o WHERE o.empresa_id = @padaria AND o.tipo = 'FGTS';

INSERT INTO lembretes (obrigacao_id, usuario_id, canal, mensagem, criado_em)
SELECT o.id, @contador, 'email',
       'Olá, Boa Vista! Sua guia de ICMS está vencida. Podemos emitir a segunda via ainda hoje.',
       DATE_SUB(NOW(), INTERVAL 1 DAY)
FROM obrigacoes o WHERE o.empresa_id = @mercado AND o.tipo = 'ICMS';

INSERT INTO lembretes (obrigacao_id, usuario_id, canal, mensagem, criado_em)
SELECT o.id, @admin, 'copiado',
       'Parcelamento de débitos federais: parcela 14 de 60 vence em 7 dias.',
       DATE_SUB(NOW(), INTERVAL 3 HOUR)
FROM obrigacoes o WHERE o.empresa_id = @tech AND o.tipo = 'OUTRA';

-- ---------------------------------------------------------------------------
-- 6. Avaliações de usabilidade (q1 a q5 de 1 a 5, nps de 0 a 10)
-- ---------------------------------------------------------------------------
INSERT INTO avaliacoes (usuario_id, perfil, q1, q2, q3, q4, q5, nps, comentario, criado_em) VALUES
  (@contador, 'contador', 5, 5, 4, 4, 5, 10, 'O semáforo me poupou de abrir a planilha toda manhã.', DATE_SUB(NOW(), INTERVAL 2 DAY)),
  (@contador, 'contador', 4, 5, 5, 4, 4, 9,  'O lembrete pronto para o WhatsApp é o que mais uso.',    DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (@admin,    'admin',    4, 4, 3, 5, 4, 8,  NULL,                                                      DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (@admin,    'admin',    3, 4, 3, 3, 3, 6,  'Faltaria anexar o PDF da guia.',                          DATE_SUB(NOW(), INTERVAL 5 HOUR));

COMMIT;
