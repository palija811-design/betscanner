# Signal Pitch — backend

Análisis diario de partidos con scoring híbrido (estadística + IA) para
mercados **BTTS** y **Over/Under 2.5**, con un **laboratorio de estrategias
que compiten en paralelo y evolucionan** según sus resultados reales.

Stack: PHP 8.2 + MariaDB + Docker/Easypanel · API-Football (free) · Claude API.

---

## Las dos capas del sistema

### 1. Scoring de partidos (tres motores)
- **stat** — solo pesos estadísticos (control).
- **hybrid** — estadística + IA que ajusta el score dentro de un margen y
  redacta el veredicto. Es la baseline.
- **reasoning** — la IA recibe el **contexto de la previa** (forma, medias,
  %BTTS, qué se juega cada equipo, H2H, bajas y, opcionalmente, búsqueda web)
  y **razona como un analista**, no rellena una fórmula. Réplica del análisis
  cualitativo que se hace leyendo una previa. Más caro; se reserva para pocas
  estrategias / ligas top.

### 2. Laboratorio evolutivo de estrategias
Cada **estrategia** es una configuración del motor (pesos, grado de IA,
umbral, motor). Todas puntúan **los mismos partidos** en paralelo y cada
predicción se guarda por separado. Tras los partidos se liquidan con acierto
y **ROI**. Un cron evolutivo pide a la IA nuevas variaciones de las mejores,
las mete a competir, poda las malas y corona a la campeona (que alimenta el
dashboard público).

```
             [ ingest_v2 ]  todas las estrategias puntúan cada partido
                   |
                   v
             [ predictions ]  1 fila por (estrategia, fixture, mercado)
                   |
   (tras partidos) v
             [ settle ]  trae resultado, calcula acierto + ROI
                   |
                   v
             [ evolve ]  la IA propone variaciones; poda; corona campeón
```

---

## Archivos

```
config/config.php         credenciales via entorno
db/schema.sql             tablas base + vista del dashboard
db/02_strategies.sql      estrategias, predicciones, resultados, vistas de rendimiento
db/03_seed_strategies.sql estrategias semilla (variedad inicial)
src/Db.php                conexion PDO
src/ApiFootball.php       cliente con presupuesto diario de requests
src/Scorer.php            motor estadistico (pesos)
src/ClaudeScorer.php      motor hybrid: ajusta score + veredicto
src/ReasoningScorer.php   motor reasoning: juicio cualitativo tipo-analista (punto 1)
src/StrategyRunner.php    ejecuta TODAS las estrategias sobre cada partido
src/api.php               endpoint del dashboard (senales de la campeona)
src/performance.php       endpoint del laboratorio (ranking acierto+ROI)
cron/ingest.php           ingesta simple (una sola estrategia) - modo basico
cron/ingest_v2.php        ingesta multi-estrategia - modo laboratorio
cron/settle.php           liquidacion de resultados (acierto + ROI)
cron/evolve.php           ciclo evolutivo (la IA propone estrategias)
```

## Puesta en marcha

```bash
mysql -u root -p -e "CREATE DATABASE signalpitch CHARACTER SET utf8mb4;"
mysql -u root -p signalpitch < db/schema.sql
mysql -u root -p signalpitch < db/02_strategies.sql
mysql -u root -p signalpitch < db/03_seed_strategies.sql
```

Define las variables de `.env.example` en Easypanel. Luego los cron:

```
15 8 * * *   php /app/cron/ingest_v2.php  >> /var/log/signalpitch.log 2>&1   # manana
30 1 * * *   php /app/cron/settle.php     >> /var/log/signalpitch.log 2>&1   # noche
0  3 * * 1   php /app/cron/evolve.php     >> /var/log/signalpitch.log 2>&1   # semanal
```

## Como "gana" una estrategia

Dos metricas, ambas en las vistas:
- **hit_rate** — % de acierto sobre picks jugados y resueltos.
- **roi_pct** — beneficio a stake 1 por pick (win: cuota-1; loss: -1).

El campeon se elige por **ROI** (desempate por acierto) y **solo entre
estrategias con muestra suficiente** (MIN_PICKS_TO_CHAMPION, por defecto 60),
para no coronar a una que tuvo suerte en 10 partidos.

> Para ROI real necesitas guardar la **cuota** en `predictions.odds`. Si no hay
> cuota, `settle` asume 1.90 por defecto (aproxima, no es exacto). Fuente de
> cuotas: endpoint `/odds` de API-Football (gasta requests) o carga manual.

## Sobre la evolucion — expectativas honestas

- El laboratorio **necesita volumen y tiempo**. Con pocas decenas de picks las
  metricas son ruido. Fia calibraciones a partir de ~200-300 picks por mercado.
- Que una estrategia mejore el ROI historico **no garantiza** que lo mantenga:
  cuidado con el sobreajuste. Por eso se conservan `stat_pure` y `baseline`
  como controles y nunca se podan.
- Aunque el sistema afine la seleccion, la ventaja de la casa sigue existiendo.
  Es una herramienta de analisis, no una maquina de ganar dinero.

## Aviso

Herramienta de analisis informativo. No es consejo de apuesta ni garantia de
resultado. Juego responsable (Espana): FEJAR - autoprohibicion RGIAJ.
