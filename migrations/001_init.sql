CREATE TABLE users (
  id {{PK}},
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL,
  active TINYINT NOT NULL DEFAULT 1,
  created_at VARCHAR(19) NOT NULL
){{ENGINE}};

CREATE TABLE login_attempts (
  id {{PK}},
  email VARCHAR(190) NOT NULL,
  ip VARCHAR(64) NOT NULL,
  at VARCHAR(19) NOT NULL
){{ENGINE}};

CREATE INDEX idx_login_attempts ON login_attempts (email, at);

CREATE TABLE rate_limits (
  id {{PK}},
  bucket VARCHAR(120) NOT NULL,
  at VARCHAR(19) NOT NULL
){{ENGINE}};

CREATE INDEX idx_rate_limits ON rate_limits (bucket, at);

CREATE TABLE settings (
  skey VARCHAR(80) PRIMARY KEY,
  svalue TEXT NOT NULL
){{ENGINE}};

CREATE TABLE categories (
  id {{PK}},
  name VARCHAR(120) NOT NULL,
  sort INT NOT NULL DEFAULT 0,
  active TINYINT NOT NULL DEFAULT 1,
  is_pizza TINYINT NOT NULL DEFAULT 0
){{ENGINE}};

CREATE TABLE products (
  id {{PK}},
  category_id INT UNSIGNED NULL,
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  image VARCHAR(190) NULL,
  base_price_cents INT NOT NULL DEFAULT 0,
  available TINYINT NOT NULL DEFAULT 1,
  badge VARCHAR(80) NULL,
  sort INT NOT NULL DEFAULT 0,
  FOREIGN KEY (category_id) REFERENCES categories(id)
){{ENGINE}};

CREATE TABLE product_sizes (
  id {{PK}},
  product_id INT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL,
  detail VARCHAR(120) NULL,
  price_cents INT NOT NULL,
  available TINYINT NOT NULL DEFAULT 1,
  sort INT NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
){{ENGINE}};

CREATE TABLE option_groups (
  id {{PK}},
  category_id INT UNSIGNED NULL,
  product_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  min_sel INT NOT NULL DEFAULT 0,
  max_sel INT NOT NULL DEFAULT 1,
  sort INT NOT NULL DEFAULT 0,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
){{ENGINE}};

CREATE TABLE options (
  id {{PK}},
  group_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  price_cents INT NOT NULL DEFAULT 0,
  available TINYINT NOT NULL DEFAULT 1,
  sort INT NOT NULL DEFAULT 0,
  FOREIGN KEY (group_id) REFERENCES option_groups(id) ON DELETE CASCADE
){{ENGINE}};

CREATE TABLE option_size_prices (
  option_id INT UNSIGNED NOT NULL,
  size_name VARCHAR(60) NOT NULL,
  price_cents INT NOT NULL,
  PRIMARY KEY (option_id, size_name),
  FOREIGN KEY (option_id) REFERENCES options(id) ON DELETE CASCADE
){{ENGINE}};

CREATE TABLE delivery_zones (
  id {{PK}},
  name VARCHAR(120) NOT NULL,
  fee_cents INT NOT NULL DEFAULT 0,
  min_order_cents INT NOT NULL DEFAULT 0,
  active TINYINT NOT NULL DEFAULT 1
){{ENGINE}};

CREATE TABLE customers (
  id {{PK}},
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(20) NOT NULL UNIQUE,
  created_at VARCHAR(19) NOT NULL,
  last_order_at VARCHAR(19) NULL
){{ENGINE}};

CREATE TABLE orders (
  id {{PK}},
  public_token VARCHAR(64) NOT NULL UNIQUE,
  idempotency_key VARCHAR(80) NOT NULL UNIQUE,
  customer_id INT UNSIGNED NOT NULL,
  customer_name VARCHAR(120) NOT NULL,
  customer_phone VARCHAR(20) NOT NULL,
  type VARCHAR(10) NOT NULL,
  zone_id INT UNSIGNED NULL,
  zone_name VARCHAR(120) NULL,
  addr_street VARCHAR(160) NULL,
  addr_number VARCHAR(20) NULL,
  addr_complement VARCHAR(120) NULL,
  addr_reference VARCHAR(160) NULL,
  subtotal_cents INT NOT NULL,
  delivery_fee_cents INT NOT NULL DEFAULT 0,
  discount_cents INT NOT NULL DEFAULT 0,
  total_cents INT NOT NULL,
  payment_method VARCHAR(30) NOT NULL,
  payment_label VARCHAR(80) NOT NULL,
  payment_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  change_for_cents INT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'received',
  version INT NOT NULL DEFAULT 1,
  notes VARCHAR(300) NULL,
  estimate_minutes INT NULL,
  cancel_reason VARCHAR(300) NULL,
  created_at VARCHAR(19) NOT NULL,
  updated_at VARCHAR(19) NOT NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(id)
){{ENGINE}};

CREATE INDEX idx_orders_created ON orders (created_at);

CREATE INDEX idx_orders_status ON orders (status);

CREATE TABLE order_items (
  id {{PK}},
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  name VARCHAR(255) NOT NULL,
  size_name VARCHAR(60) NULL,
  qty INT NOT NULL,
  unit_price_cents INT NOT NULL,
  total_cents INT NOT NULL,
  notes VARCHAR(200) NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
){{ENGINE}};

CREATE TABLE order_item_options (
  id {{PK}},
  item_id INT UNSIGNED NOT NULL,
  group_name VARCHAR(120) NOT NULL,
  option_name VARCHAR(120) NOT NULL,
  price_cents INT NOT NULL DEFAULT 0,
  FOREIGN KEY (item_id) REFERENCES order_items(id) ON DELETE CASCADE
){{ENGINE}};

CREATE TABLE order_events (
  id {{PK}},
  order_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  user_name VARCHAR(120) NULL,
  kind VARCHAR(30) NOT NULL,
  from_value VARCHAR(30) NULL,
  to_value VARCHAR(30) NULL,
  note VARCHAR(300) NULL,
  created_at VARCHAR(19) NOT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
){{ENGINE}};

CREATE TABLE print_jobs (
  id {{PK}},
  uuid VARCHAR(36) NOT NULL UNIQUE,
  order_id INT UNSIGNED NOT NULL,
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
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
){{ENGINE}};

CREATE INDEX idx_print_status ON print_jobs (status);

CREATE TABLE audit_log (
  id {{PK}},
  user_id INT UNSIGNED NULL,
  user_name VARCHAR(120) NULL,
  action VARCHAR(60) NOT NULL,
  detail VARCHAR(400) NULL,
  created_at VARCHAR(19) NOT NULL
){{ENGINE}};
