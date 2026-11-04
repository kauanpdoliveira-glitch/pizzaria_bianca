-- Observações de canais externos (ex.: iFood). NÃO alteram preços nem produtos publicados:
-- guardam o que foi visto, a origem, a data e a situação, e permitem reexecutar a importação sem duplicar.
CREATE TABLE channel_observations (
  id {{PK}},
  source VARCHAR(30) NOT NULL,
  source_key VARCHAR(160) NOT NULL,
  kind VARCHAR(20) NOT NULL,
  product_id INT UNSIGNED NULL,
  size_name VARCHAR(60) NULL,
  name VARCHAR(190) NOT NULL,
  observed_cents INT NULL,
  status VARCHAR(24) NOT NULL,
  note TEXT NULL,
  source_url VARCHAR(400) NULL,
  image_origin VARCHAR(190) NULL,
  observed_at VARCHAR(19) NOT NULL,
  UNIQUE (source, source_key),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
){{ENGINE}};

CREATE INDEX idx_observations_product ON channel_observations (product_id);
