-- Impressoras configuráveis pelo painel: cadastro completo por destino, pausa/armação da estação, identificador próprio.
ALTER TABLE print_stations ADD COLUMN code VARCHAR(40) NULL;

ALTER TABLE print_stations ADD COLUMN paused TINYINT NOT NULL DEFAULT 0;

ALTER TABLE print_stations ADD COLUMN armed TINYINT NOT NULL DEFAULT 0;

ALTER TABLE print_stations ADD COLUMN armed_by VARCHAR(120) NULL;

ALTER TABLE print_stations ADD COLUMN armed_at VARCHAR(19) NULL;

ALTER TABLE print_stations ADD COLUMN instances_json TEXT NULL;

CREATE UNIQUE INDEX uq_station_code ON print_stations (code);

ALTER TABLE print_station_map ADD COLUMN label VARCHAR(80) NULL;

ALTER TABLE print_station_map ADD COLUMN manufacturer VARCHAR(40) NULL;

ALTER TABLE print_station_map ADD COLUMN model VARCHAR(60) NULL;

ALTER TABLE print_station_map ADD COLUMN connection VARCHAR(10) NOT NULL DEFAULT 'usb';

ALTER TABLE print_station_map ADD COLUMN mode VARCHAR(10) NOT NULL DEFAULT 'escpos';

ALTER TABLE print_station_map ADD COLUMN paper INT NOT NULL DEFAULT 80;

ALTER TABLE print_station_map ADD COLUMN margin INT NOT NULL DEFAULT 0;

ALTER TABLE print_station_map ADD COLUMN copies INT NOT NULL DEFAULT 1;

ALTER TABLE print_station_map ADD COLUMN active TINYINT NOT NULL DEFAULT 1;

ALTER TABLE print_station_map ADD COLUMN notes VARCHAR(200) NULL;

UPDATE print_station_map SET paper = CASE WHEN cols <= 36 THEN 58 ELSE 80 END;
