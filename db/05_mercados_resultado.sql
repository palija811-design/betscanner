-- ============================================================
--  SIGNAL PITCH · Migración 05
--  Mercados de resultado (1X2 y Doble Oportunidad) + cuota
-- ============================================================
SET NAMES utf8mb4;

-- Ampliar el ENUM de mercados en signals y predictions para los nuevos
ALTER TABLE signals
  MODIFY COLUMN market ENUM('BTTS','OVER','UNDER','HOME','AWAY','DC_1X','DC_X2') NOT NULL;

ALTER TABLE predictions
  MODIFY COLUMN market ENUM('BTTS','OVER','UNDER','HOME','AWAY','DC_1X','DC_X2') NOT NULL;

ALTER TABLE fixture_results
  MODIFY COLUMN market ENUM('BTTS','OVER','UNDER','HOME','AWAY','DC_1X','DC_X2') NOT NULL;

-- Guardar la cuota junto a la señal (para mostrarla y, más adelante, medir value)
ALTER TABLE signals
  ADD COLUMN IF NOT EXISTS odds DECIMAL(5,2) NULL AFTER model_used,
  ADD COLUMN IF NOT EXISTS odds_source VARCHAR(30) NULL AFTER odds;

-- Dos estrategias nuevas para el laboratorio: 1X2 y Doble Oportunidad.
-- Empiezan activas para que acumulen datos y dejen de mostrar demo.
INSERT INTO strategies (code, name, description, config_json, origin) VALUES
('result_1x2', 'Resultado 1X2',
 'Predice ganador (local/visitante) con fuerza relativa + ventaja de campo.',
 JSON_OBJECT('engine','result','markets', JSON_ARRAY('HOME','AWAY'),'min_score',55,'use_web',false),
 'manual'),
('doble_oportunidad', 'Doble Oportunidad',
 'Mercado conservador: local-o-empate / visitante-o-empate. Bien cubierto en Polymarket.',
 JSON_OBJECT('engine','result','markets', JSON_ARRAY('DC_1X','DC_X2'),'min_score',60,'use_web',false),
 'manual')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Vista del dashboard actualizada para incluir la cuota
CREATE OR REPLACE VIEW v_today_signals AS
SELECT
  s.id, s.market, s.final_score, s.stat_score, s.confidence,
  s.ai_verdict, s.ai_risk, s.research_verdict, s.research_adjustment,
  s.factors_json, s.model_used, s.odds, s.odds_source,
  f.id AS fixture_id, f.kickoff_utc, f.status, f.polymarket_url,
  l.name AS league, l.country,
  th.name AS home, th.logo_url AS home_logo,
  ta.name AS away, ta.logo_url AS away_logo
FROM signals s
JOIN fixtures f ON f.id = s.fixture_id
JOIN leagues  l ON l.id = f.league_id
JOIN teams    th ON th.id = f.home_id
JOIN teams    ta ON ta.id = f.away_id
WHERE DATE(f.kickoff_utc) = UTC_DATE()
ORDER BY s.final_score DESC;
