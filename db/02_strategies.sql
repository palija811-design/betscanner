-- ============================================================
--  SIGNAL PITCH · Migración 02
--  Estrategias en paralelo + resultados + métricas (acierto/ROI)
-- ============================================================
--  Ejecutar tras schema.sql:
--    mysql -u USER -p signalpitch < 02_strategies.sql
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
--  ESTRATEGIAS: cada una es una configuración distinta del
--  motor (pesos, grado de peso IA, umbrales, modo de razonamiento).
--  Compiten en paralelo sobre los MISMOS partidos.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS strategies (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(40)   NOT NULL UNIQUE,     -- p.ej. "baseline", "ai_heavy_v2"
  name          VARCHAR(120)  NOT NULL,
  description   VARCHAR(400)  NULL,
  -- configuración completa de la estrategia como JSON:
  --  { "engine":"stat|hybrid|reasoning",
  --    "ai_weight":0.0..1.0,          // cuánto pesa la IA vs estadística
  --    "ai_margin":12,                // margen de ajuste IA (si aplica)
  --    "weights": { "BTTS":{...}, "OVER":{...}, "UNDER":{...} },
  --    "min_score":55,                // umbral de visualización
  --    "use_web":false }              // búsqueda web en el razonamiento
  config_json   JSON          NOT NULL,
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  is_champion   TINYINT(1)    NOT NULL DEFAULT 0,   -- la que alimenta el dashboard público
  origin        ENUM('manual','ai') NOT NULL DEFAULT 'manual',
  parent_id     INT UNSIGNED  NULL,                 -- de qué estrategia deriva (linaje)
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_active (is_active),
  CONSTRAINT fk_strat_parent FOREIGN KEY (parent_id) REFERENCES strategies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  PREDICCIONES por estrategia. Una fila por (estrategia, fixture,
--  mercado). Aquí es donde cada estrategia deja su "apuesta" para
--  poder compararlas luego contra el resultado real.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS predictions (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  strategy_id   INT UNSIGNED   NOT NULL,
  fixture_id    BIGINT UNSIGNED NOT NULL,
  market        ENUM('BTTS','OVER','UNDER') NOT NULL,
  score         TINYINT UNSIGNED NOT NULL,          -- score final de ESTA estrategia
  confidence    ENUM('fuerte','moderada','debil') NOT NULL,
  picked        TINYINT(1)     NOT NULL DEFAULT 0,   -- ¿la estrategia la "juega"? (score >= su umbral)
  factors_json  JSON           NULL,
  ai_verdict    VARCHAR(500)   NULL,
  ai_risk       VARCHAR(300)   NULL,
  reasoning_json JSON          NULL,                 -- razonamiento estructurado (motor reasoning)
  model_used    VARCHAR(40)    NULL,
  odds          DECIMAL(5,2)   NULL,                 -- cuota capturada (para ROI)
  -- resultado (se rellena tras el partido)
  outcome       ENUM('win','loss','void','pending') NOT NULL DEFAULT 'pending',
  profit        DECIMAL(6,2)   NULL,                 -- beneficio a stake 1 (win: odds-1; loss: -1)
  settled_at    TIMESTAMP      NULL,
  created_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_strat_fix_mkt (strategy_id, fixture_id, market),
  KEY idx_strategy (strategy_id, outcome),
  KEY idx_fixture (fixture_id),
  KEY idx_picked (strategy_id, picked, outcome),
  CONSTRAINT fk_pred_strat   FOREIGN KEY (strategy_id) REFERENCES strategies(id) ON DELETE CASCADE,
  CONSTRAINT fk_pred_fixture FOREIGN KEY (fixture_id)  REFERENCES fixtures(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  RESULTADO real de cada mercado por fixture (calculado una vez,
--  compartido por todas las estrategias que predijeron ese partido).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fixture_results (
  fixture_id    BIGINT UNSIGNED NOT NULL,
  market        ENUM('BTTS','OVER','UNDER') NOT NULL,
  hit           TINYINT(1)     NOT NULL,             -- 1 si el mercado se cumplió
  home_goals    TINYINT UNSIGNED NOT NULL,
  away_goals    TINYINT UNSIGNED NOT NULL,
  settled_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (fixture_id, market),
  CONSTRAINT fk_res_fixture FOREIGN KEY (fixture_id) REFERENCES fixtures(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  VISTA de rendimiento por estrategia: acierto y ROI sobre las
--  predicciones "jugadas" (picked=1) ya resueltas.
-- ------------------------------------------------------------
CREATE OR REPLACE VIEW v_strategy_performance AS
SELECT
  st.id            AS strategy_id,
  st.code, st.name, st.origin, st.is_champion,
  COUNT(*)                                   AS picks_settled,
  SUM(p.outcome='win')                       AS wins,
  SUM(p.outcome='loss')                      AS losses,
  ROUND(100 * SUM(p.outcome='win') / NULLIF(COUNT(*),0), 1) AS hit_rate,
  ROUND(SUM(p.profit), 2)                     AS total_profit,   -- a stake 1
  ROUND(100 * SUM(p.profit) / NULLIF(COUNT(*),0), 1) AS roi_pct
FROM strategies st
JOIN predictions p
  ON p.strategy_id = st.id
 AND p.picked = 1
 AND p.outcome IN ('win','loss')
GROUP BY st.id, st.code, st.name, st.origin, st.is_champion;

-- ------------------------------------------------------------
--  VISTA de rendimiento por estrategia + mercado + tramo de score.
--  Es la que usarás para calibrar (ver dónde acierta cada una).
-- ------------------------------------------------------------
CREATE OR REPLACE VIEW v_strategy_breakdown AS
SELECT
  p.strategy_id,
  p.market,
  CASE
    WHEN p.score >= 80 THEN '80-100'
    WHEN p.score >= 70 THEN '70-79'
    WHEN p.score >= 60 THEN '60-69'
    WHEN p.score >= 50 THEN '50-59'
    ELSE '<50'
  END AS score_band,
  COUNT(*)                             AS n,
  SUM(p.outcome='win')                 AS wins,
  ROUND(100*SUM(p.outcome='win')/NULLIF(COUNT(*),0),1) AS hit_rate,
  ROUND(100*SUM(p.profit)/NULLIF(COUNT(*),0),1)        AS roi_pct
FROM predictions p
WHERE p.outcome IN ('win','loss')
GROUP BY p.strategy_id, p.market, score_band;

-- ------------------------------------------------------------
--  Estrategia semilla: la "baseline" (la que ya tenías).
-- ------------------------------------------------------------
INSERT INTO strategies (code, name, description, config_json, is_champion, origin)
VALUES (
  'baseline',
  'Baseline híbrida',
  'Pesos estadísticos originales + ajuste IA acotado ±12. Punto de partida.',
  JSON_OBJECT(
    'engine','hybrid',
    'ai_weight', 0.3,
    'ai_margin', 12,
    'min_score', 55,
    'use_web', false,
    'weights', JSON_OBJECT(
      'BTTS', JSON_OBJECT('attackHome',0.20,'attackAway',0.20,'leaky',0.25,'bttsHist',0.25,'h2h',0.10),
      'OVER', JSON_OBJECT('goalsAvg',0.35,'attackHome',0.15,'attackAway',0.15,'leaky',0.20,'h2h',0.15),
      'UNDER',JSON_OBJECT('lowGoals',0.40,'solidHome',0.20,'solidAway',0.20,'h2h',0.20)
    )
  ),
  1, 'manual'
) ON DUPLICATE KEY UPDATE name=VALUES(name);
