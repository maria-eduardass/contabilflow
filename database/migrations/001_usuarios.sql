CREATE TABLE usuarios (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome              VARCHAR(120) NOT NULL,
  email             VARCHAR(180) NOT NULL,
  senha_hash        VARCHAR(255) NOT NULL,
  perfil            ENUM('admin','contador') NOT NULL,
  ativo             TINYINT(1) NOT NULL DEFAULT 1,
  tentativas_falhas TINYINT UNSIGNED NOT NULL DEFAULT 0,
  bloqueado_ate     DATETIME NULL,
  criado_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
