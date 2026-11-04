-- Impressão com QZ Tray: destinos estáveis (cozinha, copa, balcao), estações, mapeamento de impressoras, fila com reserva e registro de assinaturas.
-- Destinos por categoria/produto usam CÓDIGOS estáveis (nunca nomes). Categoria sem destino gera pendência visível.
ALTER TABLE products ADD COLUMN print_sector VARCHAR(20) NULL;

-- Migração de dados única: o antigo setor "balcao" de categoria (bebidas) vira "copa"; categorias sem setor ficam explícitas.
UPDATE categories SET sector = 'copa' WHERE sector = 'balcao';

UPDATE categories SET sector = 'copa' WHERE sector IS NULL AND (LOWER(name) LIKE '%bebida%' OR LOWER(name) LIKE '%sobremesa%');

UPDATE categories SET sector = 'cozinha' WHERE sector IS NULL;

CREATE TABLE print_stations (
  id {{PK}},
  name VARCHAR(80) NOT NULL,
  active TINYINT NOT NULL DEFAULT 1,
  created_by VARCHAR(120) NULL,
  created_at VARCHAR(19) NOT NULL,
  last_seen VARCHAR(19) NULL,
  qz_connected TINYINT NOT NULL DEFAULT 0,
  qz_version VARCHAR(20) NULL,
  last_user VARCHAR(120) NULL,
  printers_json TEXT NULL
){{ENGINE}};

CREATE TABLE print_station_map (
  station_id INT UNSIGNED NOT NULL,
  sector VARCHAR(20) NOT NULL,
  printer_name VARCHAR(190) NOT NULL,
  cols INT NOT NULL DEFAULT 48,
  charset VARCHAR(12) NOT NULL DEFAULT 'ascii',
  cut TINYINT NOT NULL DEFAULT 1,
  PRIMARY KEY (station_id, sector),
  FOREIGN KEY (station_id) REFERENCES print_stations(id) ON DELETE CASCADE
){{ENGINE}};

CREATE TABLE print_jobs_new (
  id {{PK}},
  uuid VARCHAR(36) NOT NULL UNIQUE,
  order_id INT UNSIGNED NULL,
  sector VARCHAR(20) NOT NULL,
  paper INT NOT NULL DEFAULT 80,
  kind VARCHAR(10) NOT NULL DEFAULT 'original',
  status VARCHAR(12) NOT NULL DEFAULT 'queued',
  attempts INT NOT NULL DEFAULT 0,
  last_error VARCHAR(300) NULL,
  created_by VARCHAR(120) NULL,
  created_at VARCHAR(19) NOT NULL,
  claimed_at VARCHAR(19) NULL,
  finished_at VARCHAR(19) NULL,
  station_id INT UNSIGNED NULL,
  job_key VARCHAR(120) NULL UNIQUE,
  reserved_until VARCHAR(19) NULL,
  payload_hash VARCHAR(64) NULL,
  version INT NOT NULL DEFAULT 1,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
){{ENGINE}};

INSERT INTO print_jobs_new (id, uuid, order_id, sector, paper, kind, status, attempts, last_error, created_by, created_at, claimed_at, finished_at) SELECT id, uuid, order_id, sector, paper, kind, status, attempts, last_error, created_by, created_at, claimed_at, finished_at FROM print_jobs;

DROP TABLE print_jobs;

ALTER TABLE print_jobs_new RENAME TO print_jobs;

CREATE INDEX idx_print_status ON print_jobs (status);

CREATE TABLE print_sign_log (
  id {{PK}},
  station_id INT UNSIGNED NOT NULL,
  job_id INT UNSIGNED NULL,
  call_name VARCHAR(40) NOT NULL,
  digest VARCHAR(64) NOT NULL UNIQUE,
  user_name VARCHAR(120) NULL,
  ok TINYINT NOT NULL,
  reason VARCHAR(160) NULL,
  at VARCHAR(19) NOT NULL
){{ENGINE}};

CREATE INDEX idx_sign_station_at ON print_sign_log (station_id, at);
