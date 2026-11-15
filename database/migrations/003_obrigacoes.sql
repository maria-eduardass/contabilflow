CREATE TABLE obrigacoes (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id    INT UNSIGNED NOT NULL,
  tipo          ENUM('DAS','DARF','INSS','FGTS','ISS','ICMS','DCTFWEB','OUTRA') NOT NULL,
  descricao     VARCHAR(255) NULL,
  competencia   DATE NOT NULL,
  vencimento    DATE NOT NULL,
  valor         DECIMAL(12,2) NULL,
  status        ENUM('pendente','enviada','paga','cancelada') NOT NULL DEFAULT 'pendente',
  enviada_em    DATETIME NULL,
  paga_em       DATETIME NULL,
  observacoes   TEXT NULL,
  criado_por    INT UNSIGNED NOT NULL,
  versao        INT UNSIGNED NOT NULL DEFAULT 1,
  criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  dedup_key VARCHAR(40) AS (
    IF(tipo = 'OUTRA' OR status = 'cancelada', NULL,
       CONCAT(empresa_id, '|', tipo, '|', competencia))
  ) STORED,
  UNIQUE KEY uq_obrigacoes_dedup (dedup_key),
  KEY idx_obrigacoes_status_venc (status, vencimento),
  KEY idx_obrigacoes_empresa_venc (empresa_id, vencimento),
  CONSTRAINT fk_obrigacoes_empresa FOREIGN KEY (empresa_id)
    REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_obrigacoes_criador FOREIGN KEY (criado_por)
    REFERENCES usuarios(id) ON DELETE RESTRICT,
  CONSTRAINT chk_obrigacoes_valor       CHECK (valor IS NULL OR valor >= 0),
  CONSTRAINT chk_obrigacoes_competencia CHECK (DAYOFMONTH(competencia) = 1),
  CONSTRAINT chk_obrigacoes_paga        CHECK ((status = 'paga') = (paga_em IS NOT NULL)),
  CONSTRAINT chk_obrigacoes_outra       CHECK (tipo <> 'OUTRA' OR descricao IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
