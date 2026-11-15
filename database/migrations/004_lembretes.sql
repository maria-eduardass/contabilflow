CREATE TABLE lembretes (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  obrigacao_id INT UNSIGNED NOT NULL,
  usuario_id   INT UNSIGNED NOT NULL,
  canal        ENUM('whatsapp','email','copiado') NOT NULL,
  mensagem     TEXT NOT NULL,
  criado_em    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lembretes_obrigacao (obrigacao_id, criado_em),
  CONSTRAINT fk_lembretes_obrigacao FOREIGN KEY (obrigacao_id)
    REFERENCES obrigacoes(id) ON DELETE CASCADE,
  CONSTRAINT fk_lembretes_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
