# Actualización del backend — motor v2 + dos capas + Polymarket

Qué cambia respecto a la versión anterior, y cómo aplicarlo sobre lo que ya
tengas subido.

## Qué hay de nuevo

1. **Motor recalibrado (`src/Scorer.php`)** — ahora DISCRIMINA. Los umbrales se
   centran en la realidad del fútbol, así que un partido medio da ~40-50 y solo
   los extremos superan 70. Adiós al "Over fuerte en todo".

2. **Datos fiables (`src/RecentForm.php`)** — medias sobre los últimos 10
   partidos REALES de cada equipo (todas las competiciones), no sobre la
   muestra minúscula por competición.

3. **Enlace Polymarket (`src/PolymarketLink.php`)** — usa la API pública Gamma
   para dar el enlace directo al mercado del partido. Si Polymarket está
   bloqueado desde el VPS, cae al enlace de la liga sin romperse.

4. **Flujo de dos capas (`cron/ingest_v3.php`)** — capa 1 estadística para
   todos; capa 2 de investigación web (ReasoningScorer) SOLO para los partidos
   con score >= 65. Es lo que corrige señales como el "Yverdon-Wil": el motor
   dice Over alto, la investigación lo rebaja al ver el H2H.

## Cómo aplicarlo

### Si ya tienes el proyecto subido a GitHub y corriendo en Easypanel

1. **Sube los cambios al repo** (desde tu carpeta local, tras descomprimir esta
   versión encima):
   ```bash
   git add .
   git commit -m "Motor v2 recalibrado + dos capas + Polymarket"
   git push
   ```

2. **Aplica la migración de base de datos** (añade columnas nuevas):
   ```bash
   mysql -u signalpitch -p signalpitch < db/04_polymarket.sql
   ```

3. **Redeploy en Easypanel** (el servicio se reconstruye con el nuevo código y
   el crontab ya apunta a `ingest_v3.php`).

4. **Prueba manual** en la consola del servicio:
   ```bash
   php /var/www/html/cron/ingest_v3.php
   ```

### Sobre la capa de investigación (capa 2)

- Usa tu clave de Anthropic (ya configurada) con web search activado.
- Solo se dispara en partidos con score >= 65, para no gastar tokens de más.
- En ligas menores encuentra poca información; rinde mucho mejor en Champions,
  Europa y primeras divisiones.

### Sobre Polymarket desde el VPS

Polymarket bloquea por geolocalización. Si tu VPS está en España, es probable
que `PolymarketLink` no consiga acceso y caiga siempre al enlace de respaldo de
la liga. Opciones:
- Aceptar el enlace de respaldo (lleva a la página de la liga, el usuario busca
  su partido: un clic más).
- Enrutar solo esas llamadas por un proxy con salida en región permitida.
No metas credenciales de wallet ni ejecución de apuestas en el servidor: el
enlace es solo un hipervínculo, sin riesgo.

## Aviso

Herramienta de análisis informativo. No es consejo de apuesta ni garantía de
resultado. Que el motor discrimine mejor no elimina la ventaja de la casa.
Juego responsable (España): FEJAR · autoprohibición RGIAJ.
