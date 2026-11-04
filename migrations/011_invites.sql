-- Convites de acesso: o funcionário define a PRÓPRIA senha por um link de uso único (token guardado só como hash).
ALTER TABLE users ADD COLUMN activated_at VARCHAR(19) NULL;

ALTER TABLE users ADD COLUMN invited_by VARCHAR(120) NULL;

ALTER TABLE users ADD COLUMN badge VARCHAR(40) NULL;

UPDATE users SET activated_at = created_at WHERE activated_at IS NULL;

CREATE TABLE user_invites (
  id {{PK}},
  user_id INT UNSIGNED NOT NULL,
  token_hash VARCHAR(64) NOT NULL UNIQUE,
  expires_at VARCHAR(19) NOT NULL,
  used_at VARCHAR(19) NULL,
  revoked_at VARCHAR(19) NULL,
  sent_ok TINYINT NOT NULL DEFAULT 0,
  created_by VARCHAR(120) NULL,
  created_at VARCHAR(19) NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
){{ENGINE}};

CREATE INDEX idx_invites_user ON user_invites (user_id);
