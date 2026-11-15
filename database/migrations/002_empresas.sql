CREATE TABLE empresas (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  razao_social   VARCHAR(180) NOT NULL,
  nome_fantasia  VARCHAR(180) NULL,
  cnpj           CHAR(14) NOT NULL,
  regime         ENUM('mei','simples','presumido','real') NOT NULL,
  email          VARCHAR(180) NULL,
  whatsapp       VARCHAR(13) NULL,
  responsavel_id INT UNSIGNED NULL,
  ativo          TINYINT(1) NOT NULL DEFAULT 1,
  versao         INT UNSIGNED NOT NULL DEFAULT 1,
  criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_empresas_cnpj (cnpj),
  KEY idx_empresas_razao (razao_social),
  KEY idx_empresas_regime_ativo (regime, ativo),
  CONSTRAINT fk_empresas_responsavel FOREIGN KEY (responsavel_id)
    REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT chk_empresas_cnpj     CHECK (cnpj REGEXP '^[0-9]{14}$'),
  CONSTRAINT chk_empresas_whatsapp CHECK (whatsapp IS NULL OR whatsapp REGEXP '^55[0-9]{10,11}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
