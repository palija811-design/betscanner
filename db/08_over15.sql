-- ============================================================
--  SIGNAL PITCH · Migración 08
--  Mercado Over/Under 1.5 en el laboratorio
-- ============================================================
SET NAMES utf8mb4;

-- Ampliar el ENUM de mercados para incluir los de 1.5
ALTER TABLE predictions
  MODIFY COLUMN market ENUM('BTTS','OVER','UNDER','HOME','AWAY','DC_1X','DC_X2','OVER15','UNDER15') NOT NULL;

ALTER TABLE signals
  MODIFY COLUMN market ENUM('BTTS','OVER','UNDER','HOME','AWAY','DC_1X','DC_X2','OVER15','UNDER15') NOT NULL;

ALTER TABLE fixture_results
  MODIFY COLUMN market ENUM('BTTS','OVER','UNDER','HOME','AWAY','DC_1X','DC_X2','OVER15','UNDER15') NOT NULL;

-- Estrategia de laboratorio para Over/Under 1.5 (calibración propia)
INSERT INTO strategies (code, name, description, config_json, origin) VALUES
('goles_15', 'Over/Under 1.5',
 'Mercado de goles 1.5 con calibración propia (superar 1.5 es más común que 2.5).',
 JSON_OBJECT('engine','stat','markets', JSON_ARRAY('OVER15','UNDER15'),'min_score',60),
 'manual')
ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), config_json=VALUES(config_json), is_active=1;
