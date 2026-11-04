UPDATE users SET role = 'owner' WHERE role = 'admin';

ALTER TABLE users ADD COLUMN session_version INT NOT NULL DEFAULT 1;

ALTER TABLE users ADD COLUMN perms TEXT NULL;

ALTER TABLE users ADD COLUMN last_login_at VARCHAR(19) NULL;

ALTER TABLE categories ADD COLUMN sector VARCHAR(20) NULL;

ALTER TABLE orders ADD COLUMN origin VARCHAR(10) NOT NULL DEFAULT 'web';

ALTER TABLE orders ADD COLUMN visit_id INT UNSIGNED NULL;

ALTER TABLE orders ADD COLUMN tab_id INT UNSIGNED NULL;

ALTER TABLE orders ADD COLUMN table_number INT NULL;

CREATE INDEX idx_orders_tab ON orders (tab_id);

CREATE TABLE dining_tables (
  id {{PK}},
  number INT NOT NULL UNIQUE,
  active TINYINT NOT NULL DEFAULT 1
){{ENGINE}};

CREATE TABLE visits (
  id {{PK}},
  table_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  people INT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  opened_by INT UNSIGNED NULL,
  opened_by_name VARCHAR(120) NULL,
  opened_at VARCHAR(19) NOT NULL,
  closed_at VARCHAR(19) NULL,
  idempotency_key VARCHAR(80) NOT NULL UNIQUE,
  FOREIGN KEY (table_id) REFERENCES dining_tables(id)
){{ENGINE}};

CREATE INDEX idx_visits_table ON visits (table_id, status);

CREATE TABLE tabs (
  id {{PK}},
  visit_id INT UNSIGNED NOT NULL,
  table_id INT UNSIGNED NOT NULL,
  physical_number VARCHAR(20) NULL,
  open_key VARCHAR(20) NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  people INT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  discount_cents INT NOT NULL DEFAULT 0,
  discount_reason VARCHAR(300) NULL,
  fee_removed TINYINT NOT NULL DEFAULT 0,
  fee_reason VARCHAR(300) NULL,
  items_cents INT NULL,
  fee_cents INT NULL,
  total_cents INT NULL,
  opened_by INT UNSIGNED NULL,
  opened_by_name VARCHAR(120) NULL,
  opened_at VARCHAR(19) NOT NULL,
  bill_requested_at VARCHAR(19) NULL,
  closed_by INT UNSIGNED NULL,
  closed_by_name VARCHAR(120) NULL,
  closed_at VARCHAR(19) NULL,
  version INT NOT NULL DEFAULT 1,
  FOREIGN KEY (visit_id) REFERENCES visits(id),
  FOREIGN KEY (table_id) REFERENCES dining_tables(id)
){{ENGINE}};

CREATE INDEX idx_tabs_status ON tabs (status);

CREATE TABLE tab_payments (
  id {{PK}},
  tab_id INT UNSIGNED NOT NULL,
  visit_id INT UNSIGNED NOT NULL,
  amount_cents INT NOT NULL,
  method VARCHAR(30) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'reported',
  reported_by INT UNSIGNED NULL,
  reported_by_name VARCHAR(120) NULL,
  reported_at VARCHAR(19) NOT NULL,
  confirmed_by INT UNSIGNED NULL,
  confirmed_by_name VARCHAR(120) NULL,
  confirmed_at VARCHAR(19) NULL,
  note VARCHAR(300) NULL,
  shift_id INT UNSIGNED NULL,
  idempotency_key VARCHAR(80) NOT NULL UNIQUE,
  FOREIGN KEY (tab_id) REFERENCES tabs(id)
){{ENGINE}};

CREATE TABLE cash_shifts (
  id {{PK}},
  opened_by INT UNSIGNED NULL,
  opened_by_name VARCHAR(120) NULL,
  opened_at VARCHAR(19) NOT NULL,
  opening_cents INT NOT NULL DEFAULT 0,
  closed_by INT UNSIGNED NULL,
  closed_by_name VARCHAR(120) NULL,
  closed_at VARCHAR(19) NULL,
  expected_cents INT NULL,
  counted_cents INT NULL,
  diff_cents INT NULL,
  close_note VARCHAR(300) NULL,
  open_key INT NULL UNIQUE
){{ENGINE}};

CREATE TABLE cash_moves (
  id {{PK}},
  shift_id INT UNSIGNED NOT NULL,
  kind VARCHAR(10) NOT NULL,
  amount_cents INT NOT NULL,
  reason VARCHAR(300) NOT NULL,
  by_id INT UNSIGNED NULL,
  by_name VARCHAR(120) NULL,
  created_at VARCHAR(19) NOT NULL,
  FOREIGN KEY (shift_id) REFERENCES cash_shifts(id)
){{ENGINE}};

CREATE TABLE floor_events (
  id {{PK}},
  tab_id INT UNSIGNED NULL,
  visit_id INT UNSIGNED NULL,
  table_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  user_name VARCHAR(120) NULL,
  kind VARCHAR(30) NOT NULL,
  note VARCHAR(400) NULL,
  created_at VARCHAR(19) NOT NULL
){{ENGINE}};

CREATE INDEX idx_floor_events_tab ON floor_events (tab_id);
