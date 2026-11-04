ALTER TABLE products ADD COLUMN hidden TINYINT NOT NULL DEFAULT 0;

ALTER TABLE categories ADD COLUMN parent_id INT UNSIGNED NULL;

ALTER TABLE categories ADD COLUMN max_flavors INT NULL;

ALTER TABLE categories ADD COLUMN allow_removals TINYINT NOT NULL DEFAULT 1;

ALTER TABLE options ADD COLUMN description VARCHAR(200) NULL;

ALTER TABLE options ADD COLUMN size_restricted TINYINT NOT NULL DEFAULT 0;

CREATE TABLE product_ingredients (
  id {{PK}},
  product_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  removable TINYINT NOT NULL DEFAULT 1,
  sort INT NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
){{ENGINE}};

CREATE INDEX idx_ingredients_product ON product_ingredients (product_id);

CREATE TABLE password_resets (
  id {{PK}},
  user_id INT UNSIGNED NOT NULL,
  token_hash VARCHAR(64) NOT NULL UNIQUE,
  expires_at VARCHAR(19) NOT NULL,
  used_at VARCHAR(19) NULL,
  created_at VARCHAR(19) NOT NULL,
  ip VARCHAR(64) NULL,
  FOREIGN KEY (user_id) REFERENCES users(id)
){{ENGINE}};
