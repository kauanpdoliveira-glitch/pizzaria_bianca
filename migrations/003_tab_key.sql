ALTER TABLE tabs ADD COLUMN idempotency_key VARCHAR(80) NULL;

CREATE UNIQUE INDEX uq_tabs_idem ON tabs (idempotency_key);
