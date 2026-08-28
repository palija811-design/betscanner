-- ============================================================
--  SIGNAL PITCH · Migración 03 · Estrategias semilla
--  Variedad inicial para que el campeonato arranque con contraste.
--  La 'baseline' ya se creó en 02_strategies.sql.
-- ============================================================
SET NAMES utf8mb4;

-- Solo estadística pura (sin IA): control para saber cuánto aporta la IA
INSERT INTO strategies (code, name, description, config_json, origin) VALUES
('stat_pure', 'Estadística pura',
 'Solo pesos estadísticos, sin capa IA. Sirve de control para medir cuánto aporta la IA.',
 JSON_OBJECT('engine','stat','min_score',55,
   'weights', JSON_OBJECT(
     'BTTS', JSON_OBJECT('attackHome',0.20,'attackAway',0.20,'leaky',0.25,'bttsHist',0.25,'h2h',0.10),
     'OVER', JSON_OBJECT('goalsAvg',0.35,'attackHome',0.15,'attackAway',0.15,'leaky',0.20,'h2h',0.15),
     'UNDER',JSON_OBJECT('lowGoals',0.40,'solidHome',0.20,'solidAway',0.20,'h2h',0.20))),
 'manual')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Razonamiento IA puro (tipo-analista), sin web. Más caro, más contexto.
INSERT INTO strategies (code, name, description, config_json, origin) VALUES
('reasoning_ai', 'Razonamiento IA',
 'La IA analiza el contexto de la previa (forma, qué se juegan, H2H) y puntúa como un analista. Sin web.',
 JSON_OBJECT('engine','reasoning','min_score',60,'use_web', false),
 'manual')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Baseline pero más exigente en el umbral: menos picks, teóricamente mejor calidad
INSERT INTO strategies (code, name, description, config_json, origin) VALUES
('hybrid_strict', 'Híbrida exigente',
 'Como baseline pero solo juega señales con score >= 65. Menos volumen, apuesta por más precisión.',
 JSON_OBJECT('engine','hybrid','ai_weight',0.3,'ai_margin',12,'min_score',65,'use_web',false,
   'weights', JSON_OBJECT(
     'BTTS', JSON_OBJECT('attackHome',0.20,'attackAway',0.20,'leaky',0.25,'bttsHist',0.25,'h2h',0.10),
     'OVER', JSON_OBJECT('goalsAvg',0.35,'attackHome',0.15,'attackAway',0.15,'leaky',0.20,'h2h',0.15),
     'UNDER',JSON_OBJECT('lowGoals',0.40,'solidHome',0.20,'solidAway',0.20,'h2h',0.20))),
 'manual')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Variación que da más peso al histórico BTTS y menos al H2H
INSERT INTO strategies (code, name, description, config_json, origin) VALUES
('btts_hist_heavy', 'BTTS histórico-pesado',
 'Sube el peso del %BTTS histórico en el mercado BTTS. Hipótesis: la tendencia manda más que el H2H.',
 JSON_OBJECT('engine','hybrid','ai_weight',0.3,'ai_margin',12,'min_score',55,'use_web',false,
   'weights', JSON_OBJECT(
     'BTTS', JSON_OBJECT('attackHome',0.15,'attackAway',0.15,'leaky',0.20,'bttsHist',0.40,'h2h',0.10),
     'OVER', JSON_OBJECT('goalsAvg',0.35,'attackHome',0.15,'attackAway',0.15,'leaky',0.20,'h2h',0.15),
     'UNDER',JSON_OBJECT('lowGoals',0.40,'solidHome',0.20,'solidAway',0.20,'h2h',0.20))),
 'manual')
ON DUPLICATE KEY UPDATE name=VALUES(name);
