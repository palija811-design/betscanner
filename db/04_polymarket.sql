-- ============================================================
--  SIGNAL PITCH · Migración 04
--  Enlace de Polymarket por partido + campo de investigación IA
-- ============================================================
SET NAMES utf8mb4;

-- Enlace directo al mercado del partido en Polymarket
ALTER TABLE fixtures
  ADD COLUMN IF NOT EXISTS polymarket_url VARCHAR(300) NULL AFTER h2h_goals_avg;

-- Guardamos el veredicto de la capa de investigación web (reasoning + search)
-- separado del ai_verdict del motor hybrid, para poder comparar ambas capas.
ALTER TABLE signals
  ADD COLUMN IF NOT EXISTS research_verdict VARCHAR(600) NULL AFTER ai_risk,
  ADD COLUMN IF NOT EXISTS research_adjustment TINYINT NULL AFTER research_verdict;
  -- research_adjustment: cuántos puntos movió la investigación el score (+/-)

-- Vista del dashboard actualizada: incluye enlace Polymarket y veredicto de investigación
CREATE OR REPLACE VIEW v_today_signals AS
SELECT
  s.id, s.market, s.final_score, s.stat_score, s.confidence,
  s.ai_verdict, s.ai_risk, s.research_verdict, s.research_adjustment,
  s.factors_json, s.model_used,
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
