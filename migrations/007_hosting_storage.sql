-- Hospedagem sem disco persistente (ex.: Vercel/serverless): sessões e imagens enviadas no banco.
-- Ativadas por SESSION_DRIVER=db e UPLOAD_DRIVER=db (o padrão continua sendo sessão de arquivo e pasta public/uploads).
CREATE TABLE app_sessions (
  id VARCHAR(128) NOT NULL PRIMARY KEY,
  data TEXT NOT NULL,
  updated_at INT NOT NULL
){{ENGINE}};

CREATE INDEX idx_sessions_updated ON app_sessions (updated_at);

CREATE TABLE uploaded_files (
  name VARCHAR(64) NOT NULL PRIMARY KEY,
  mime VARCHAR(40) NOT NULL,
  data LONGBLOB NOT NULL,
  created_at VARCHAR(19) NOT NULL
){{ENGINE}};
