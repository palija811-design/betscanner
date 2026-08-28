-- ============================================================
--  SIGNAL PITCH · Esquema MariaDB
--  Dashboard de análisis de partidos (BTTS / Over-Under)
--  Motor híbrido estadística + IA
-- ============================================================
--  Ejecutar:  mysql -u USER -p signalpitch < schema.sql
--  Requiere MariaDB 10.4+ (JSON functions, utf8mb4)
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ------------------------------------------------------------
--  LIGAS que seguimos. Controla qué barremos cada día para
--  no gastar las ~100 requests/día del plan free.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leagues (
  id            INT UNSIGNED PRIMARY KEY,          -- id de API-Football
  name          VARCHAR(120)      NOT NULL,
  country       VARCHAR(80)       NULL,
  season        SMALLINT UNSIGNED NOT NULL,        -- p.ej. 2026
  is_active     TINYINT(1)        NOT NULL DEFAULT 1,
  priority      TINYINT UNSIGNED  NOT NULL DEFAULT 5, -- 1=alta (usa Sonnet)
  created_at    TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_active (is_active, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  EQUIPOS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS teams (
  id            INT UNSIGNED PRIMARY KEY,          -- id de API-Football
  name          VARCHAR(120)      NOT NULL,
  logo_url      VARCHAR(255)      NULL,
  created_at    TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  ESTADÍSTICAS DE EQUIPO (cache lento: se refresca 1x/día)
--  Guardamos las medias que alimentan el motor de scoring.
--  Se cachea agresivamente porque casi no cambia día a día.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS team_stats (
  team_id            INT UNSIGNED  NOT NULL,
  league_id          INT UNSIGNED  NOT NULL,
  season             SMALLINT UNSIGNED NOT NULL,
  played             SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  -- medias por partido
  gf_avg             DECIMAL(4,2)  NOT NULL DEFAULT 0,  -- goles a favor / partido
  ga_avg             DECIMAL(4,2)  NOT NULL DEFAULT 0,  -- goles en contra / partido
  gf_avg_home        DECIMAL(4,2)  NOT NULL DEFAULT 0,
  ga_avg_home        DECIMAL(4,2)  NOT NULL DEFAULT 0,
  gf_avg_away        DECIMAL(4,2)  NOT NULL DEFAULT 0,
  ga_avg_away        DECIMAL(4,2)  NOT NULL DEFAULT 0,
  -- porcentajes de tendencia (0..100)
  btts_pct           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  over25_pct         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  failed_to_score_pct TINYINT UNSIGNED NOT NULL DEFAULT 0,
  clean_sheet_pct    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  form               VARCHAR(10)   NULL,             -- p.ej. "WWDLW"
  raw_json           JSON          NULL,             -- respuesta cruda por si acaso
  updated_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (team_id, league_id, season),
  KEY idx_updated (updated_at),
  CONSTRAINT fk_ts_team   FOREIGN KEY (team_id)   REFERENCES teams(id)   ON DELETE CASCADE,
  CONSTRAINT fk_ts_league FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  PARTIDOS del día
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fixtures (
  id            BIGINT UNSIGNED PRIMARY KEY,        -- fixture id de API-Football
  league_id     INT UNSIGNED  NOT NULL,
  season        SMALLINT UNSIGNED NOT NULL,
  kickoff_utc   DATETIME      NOT NULL,
  status        VARCHAR(12)   NOT NULL DEFAULT 'NS', -- NS, 1H, FT...
  home_id       INT UNSIGNED  NOT NULL,
  away_id       INT UNSIGNED  NOT NULL,
  home_goals    TINYINT UNSIGNED NULL,
  away_goals    TINYINT UNSIGNED NULL,
  h2h_goals_avg DECIMAL(4,2)  NULL,                  -- media de goles en H2H recientes
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_day    (kickoff_utc),
  KEY idx_league (league_id, kickoff_utc),
  CONSTRAINT fk_fx_league FOREIGN KEY (league_id) REFERENCES leagues(id),
  CONSTRAINT fk_fx_home   FOREIGN KEY (home_id)   REFERENCES teams(id),
  CONSTRAINT fk_fx_away   FOREIGN KEY (away_id)   REFERENCES teams(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  SEÑALES: una fila por (fixture, mercado). Guarda el score
--  estadístico, el ajuste IA y el veredicto redactado.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS signals (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fixture_id     BIGINT UNSIGNED NOT NULL,
  market         ENUM('BTTS','OVER','UNDER') NOT NULL,
  stat_score     TINYINT UNSIGNED NOT NULL,          -- 0..100 solo estadística
  ai_score       TINYINT UNSIGNED NULL,              -- 0..100 tras ponderar IA
  final_score    TINYINT UNSIGNED NOT NULL,          -- el que se muestra
  confidence     ENUM('fuerte','moderada','debil') NOT NULL,
  factors_json   JSON          NOT NULL,             -- desglose de factores 0..1
  ai_verdict     VARCHAR(500)  NULL,                 -- texto de Claude
  ai_risk        VARCHAR(300)  NULL,
  model_used     VARCHAR(40)   NULL,                 -- haiku / sonnet
  computed_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fixture_market (fixture_id, market),
  KEY idx_score (final_score),
  CONSTRAINT fk_sg_fixture FOREIGN KEY (fixture_id) REFERENCES fixtures(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  LOG de uso de la API (para no pasarte del límite diario)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_usage (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  day         DATE          NOT NULL,
  provider    VARCHAR(20)   NOT NULL DEFAULT 'apifootball',
  requests    INT UNSIGNED  NOT NULL DEFAULT 0,
  UNIQUE KEY uq_day_provider (day, provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  VISTA cómoda para el frontend: señales de hoy con nombres
-- ------------------------------------------------------------
CREATE OR REPLACE VIEW v_today_signals AS
SELECT
  s.id, s.market, s.final_score, s.confidence, s.ai_verdict, s.ai_risk,
  s.factors_json, s.model_used,
  f.id AS fixture_id, f.kickoff_utc, f.status,
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

-- ------------------------------------------------------------
--  Semilla de ligas (ids reales de API-Football).
--  priority 1 = usará Sonnet en el scoring; resto Haiku.
-- ------------------------------------------------------------
INSERT INTO leagues (id, name, country, season, priority) VALUES
  (2,  'UEFA Champions League', 'World',   2026, 1),
  (3,  'UEFA Europa League',    'World',   2026, 1),
  (848,'UEFA Conference League','World',   2026, 2),
  (39, 'Premier League',        'England', 2026, 1),
  (140,'La Liga',               'Spain',   2026, 1),
  (135,'Serie A',               'Italy',   2026, 2),
  (78, 'Bundesliga',            'Germany', 2026, 2),
  (61, 'Ligue 1',               'France',  2026, 2),
  (48, 'EFL Cup',               'England', 2026, 3),
  (94, 'Primeira Liga',         'Portugal',2026, 3)
ON DUPLICATE KEY UPDATE name=VALUES(name), season=VALUES(season);
