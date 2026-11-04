-- Layouts de comandas por destino: versões IMUTÁVEIS (rascunho → ativo → arquivado). Cada trabalho guarda a versão com que nasceu.
CREATE TABLE print_layouts (
  id {{PK}},
  sector VARCHAR(20) NOT NULL,
  name VARCHAR(80) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'draft',
  version INT NOT NULL DEFAULT 1,
  data_json TEXT NOT NULL,
  parent_id INT UNSIGNED NULL,
  note VARCHAR(200) NULL,
  created_by VARCHAR(120) NULL,
  created_at VARCHAR(19) NOT NULL,
  activated_by VARCHAR(120) NULL,
  activated_at VARCHAR(19) NULL
){{ENGINE}};

CREATE INDEX idx_layouts_sector ON print_layouts (sector, status);

ALTER TABLE print_jobs ADD COLUMN layout_id INT UNSIGNED NULL;
