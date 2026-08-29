-- ============================================================
--  SIGNAL PITCH · Migración 06
--  Liquidación de las señales del dashboard + página de estadísticas
-- ============================================================
SET NAMES utf8mb4;

-- Las señales del dashboard también se liquidan, para poder medir su acierto/ROI
ALTER TABLE signals
  ADD COLUMN outcome ENUM('win','loss','void','pending') NOT NULL DEFAULT 'pending' AFTER odds_source,
  ADD COLUMN profit DECIMAL(6,2) NULL AFTER outcome,
  ADD COLUMN settled_at TIMESTAMP NULL AFTER profit;

-- Vista: acierto y ROI de las SEÑALES del dashboard, por banda de confianza y mercado
CREATE OR REPLACE VIEW v_signal_stats AS
SELECT
  CASE
    WHEN final_score >= 70 THEN 'fuerte'
    WHEN final_score >= 55 THEN 'moderada'
    ELSE 'debil'
  END AS banda,
  market,
  COUNT(*) AS n,
  SUM(outcome='win')  AS wins,
  SUM(outcome='loss') AS losses,
  ROUND(100*SUM(outcome='win')/NULLIF(COUNT(*),0),1) AS hit_rate,
  ROUND(SUM(profit),2) AS profit_unidades,
  ROUND(100*SUM(profit)/NULLIF(COUNT(*),0),1) AS roi_pct
FROM signals
WHERE outcome IN ('win','loss')
GROUP BY banda, market;
