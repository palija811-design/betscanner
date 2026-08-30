-- ============================================================
--  SIGNAL PITCH · Migración 07
--  Comparar en el laboratorio: estadística pura vs IA básica vs IA extendida
-- ============================================================
SET NAMES utf8mb4;

-- La estrategia stat_pure ya existe (estadística sola) — la dejamos como control.
-- Reconvertimos reasoning_ai en la IA EXTENDIDA (con contexto, la nueva).
UPDATE strategies
SET name = 'IA extendida',
    description = 'Razonamiento IA con contexto: desconfía de medias, detecta recién ascendidos y mismatch de nivel.',
    config_json = JSON_OBJECT('engine','reasoning','variant','extended','min_score',60,'use_web',true)
WHERE code = 'reasoning_ai';

-- Añadimos la IA BÁSICA (prompt viejo, sin contexto) para comparar.
INSERT INTO strategies (code, name, description, config_json, origin) VALUES
('reasoning_basic', 'IA básica',
 'Razonamiento IA original, sin reglas de contexto. Sirve de comparación frente a la extendida.',
 JSON_OBJECT('engine','reasoning','variant','basic','min_score',60,'use_web',true),
 'manual')
ON DUPLICATE KEY UPDATE
  name=VALUES(name), description=VALUES(description), config_json=VALUES(config_json), is_active=1;

-- Aseguramos que las tres estén activas para que compitan
UPDATE strategies SET is_active=1 WHERE code IN ('stat_pure','reasoning_ai','reasoning_basic');
