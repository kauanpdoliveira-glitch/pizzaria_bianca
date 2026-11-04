-- Destinos configuráveis: vias, evento de origem, motivo de reimpressão e cópia (snapshot) da configuração no trabalho.
ALTER TABLE print_jobs ADD COLUMN copies INT NOT NULL DEFAULT 1;

ALTER TABLE print_jobs ADD COLUMN origin VARCHAR(30) NULL;

ALTER TABLE print_jobs ADD COLUMN reason VARCHAR(200) NULL;

ALTER TABLE print_jobs ADD COLUMN snap_json TEXT NULL;

-- Sobremesas não têm destino presumido: o proprietário define por categoria/produto. Até lá o item gera pendência visível
-- (o balcão continua recebendo tudo). Só desfaz o "copa" que a migração 006 aplicou automaticamente por nome.
UPDATE categories SET sector = NULL WHERE sector = 'copa' AND LOWER(name) LIKE '%sobremesa%';
