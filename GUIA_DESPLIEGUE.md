# Guía de despliegue — Signal Pitch

Puesta en marcha en **Easypanel** (Hostinger VPS), con **MariaDB ya existente**
y subida por **Git**. Sigue los pasos en orden. Donde veas ⚙️ es un punto que
quizá tengas que ajustar a tu configuración concreta.

Tiempo estimado: 30–45 min la primera vez.

---

## Antes de empezar: lo que necesitas a mano

- [ ] Acceso a tu panel de Easypanel.
- [ ] Datos de conexión de tu MariaDB: host, puerto, usuario y contraseña con
      permiso para crear una base de datos (o una BD ya creada para esto).
- [ ] Una **API key de API-Football** (api-sports.io) — plan free vale.
- [ ] Tu **API key de Anthropic** (la que ya usas en tus proyectos).
- [ ] Una cuenta de GitHub/GitLab donde crear un repo privado.

---

## Paso 1 · Subir el proyecto a un repositorio Git

1. Crea un repo **privado** vacío (GitHub o GitLab), p.ej. `signalpitch`.
2. En tu máquina, descomprime el zip del backend y sube su contenido:

```bash
cd signalpitch
git init
git add .
git commit -m "Signal Pitch: primera versión"
git branch -M main
git remote add origin git@github.com:TU_USUARIO/signalpitch.git
git push -u origin main
```

> El `.gitignore` ya excluye `.env` y los logs, así que no subirás secretos.
> **Nunca** pongas tus API keys en un archivo que vaya al repo.

---

## Paso 2 · Preparar la base de datos

Tu MariaDB ya corre, así que solo hay que crear la BD y las tablas.

1. Conéctate a tu MariaDB (por el cliente que uses: consola, Adminer,
   phpMyAdmin, DBeaver…).

2. Crea la base de datos y un usuario para la app: ⚙️ *ajusta la contraseña*

```sql
CREATE DATABASE signalpitch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'signalpitch'@'%' IDENTIFIED BY 'PON_UNA_CONTRASEÑA_FUERTE';
GRANT ALL PRIVILEGES ON signalpitch.* TO 'signalpitch'@'%';
FLUSH PRIVILEGES;
```

3. Carga los tres SQL **en este orden** (desde consola o importando el archivo
   en tu gestor):

```bash
mysql -u signalpitch -p signalpitch < db/schema.sql
mysql -u signalpitch -p signalpitch < db/02_strategies.sql
mysql -u signalpitch -p signalpitch < db/03_seed_strategies.sql
```

   Si usas phpMyAdmin/Adminer: entra en la BD `signalpitch`, pestaña Importar,
   y sube los tres archivos uno tras otro en ese orden.

4. Comprueba que se crearon las tablas: deberías ver `leagues`, `teams`,
   `team_stats`, `fixtures`, `signals`, `strategies`, `predictions`,
   `fixture_results`, `api_usage` y varias vistas `v_...`.

---

## Paso 3 · Crear el servicio en Easypanel

1. En tu proyecto de Easypanel, **Create Service → App**.
2. Fuente: **GitHub/GitLab**. Conecta el repo `signalpitch` y la rama `main`.
3. Build: Easypanel detecta el **Dockerfile** del repo automáticamente. No
   tienes que configurar buildpack: el Dockerfile ya trae PHP 8.2 + Apache +
   cron + la extensión pdo_mysql.
4. Puerto: el contenedor sirve por el **80** (Apache). En la pestaña de red/
   dominios de Easypanel, expón ese puerto y asígnale un dominio o subdominio
   ⚙️ (p.ej. `signals.tudominio.com`). Easypanel gestiona el HTTPS.

---

## Paso 4 · Variables de entorno

En el servicio, sección **Environment**, añade estas variables ⚙️ (rellena con
tus valores reales):

```
DB_HOST=NOMBRE_INTERNO_DE_TU_MARIADB
DB_PORT=3306
DB_NAME=signalpitch
DB_USER=signalpitch
DB_PASS=la_contraseña_del_paso_2

APIFOOTBALL_KEY=tu_key_de_api_football
SEASON=2026
APIFOOTBALL_DAILY_BUDGET=90

ANTHROPIC_API_KEY=sk-ant-tu_clave
```

> **DB_HOST importante:** si tu MariaDB también está en Easypanel, el host NO es
> `localhost` sino el **nombre interno del servicio** de la base de datos (lo
> ves en la ficha del servicio MariaDB, suele ser algo como
> `nombreproyecto_mariadb`). Si tu MariaDB está fuera, pon su IP o dominio.

Guarda y **despliega** (Deploy). Easypanel construye la imagen y arranca el
contenedor con cron + Apache.

---

## Paso 5 · Primera prueba manual (sin esperar al cron)

Antes de confiar en los cron programados, lanza la ingesta a mano para ver que
todo conecta. En Easypanel, abre la **consola/terminal** del servicio y ejecuta:

```bash
php /var/www/html/cron/ingest_v2.php
```

Qué deberías ver:
- Líneas tipo `[hh:mm:ss] ingest2 · Champions League: N partidos`.
- Si dice `sin fixtures` en todas las ligas, puede ser que hoy no haya partidos
  de esas ligas — normal. Prueba otro día o añade una liga con partidos.
- Si sale un error de conexión a BD → revisa `DB_HOST` (paso 4).
- Si sale error de API-Football → revisa `APIFOOTBALL_KEY`.

Luego comprueba los endpoints en el navegador ⚙️ (tu dominio):
- `https://signals.tudominio.com/` → el dashboard de señales.
- `https://signals.tudominio.com/lab.html` → el laboratorio.
- `https://signals.tudominio.com/api.php` → JSON de señales de hoy.
- `https://signals.tudominio.com/performance.php` → JSON de rendimiento.

> Los paneles muestran datos DEMO hasta que haya señales reales del día en la
> BD. En cuanto el cron genere señales, `api.php` las devuelve y el dashboard
> las usa. El laboratorio necesita además partidos ya liquidados (paso 6) para
> mostrar acierto y ROI reales; hasta entonces enseña el ejemplo.

---

## Paso 6 · Verificar los cron

La imagen ya instala esta programación (hora de Madrid):

| Cron            | Cuándo            | Qué hace                                   |
|-----------------|-------------------|--------------------------------------------|
| `ingest_v2.php` | 08:15 cada día    | trae partidos y todas las estrategias puntúan |
| `settle.php`    | 01:30 cada día    | trae resultados, calcula acierto + ROI     |
| `evolve.php`    | 03:00 los lunes   | la IA propone nuevas estrategias; poda; corona |

Para confirmar que el cron está vivo dentro del contenedor:

```bash
crontab -l          # lista los 3 trabajos
cat /var/log/signalpitch.log   # ve la salida de las ejecuciones
```

⚙️ Si prefieres otras horas, edita `crontab.txt` en el repo, haz push y
redeploy.

> **Alternativa:** si prefieres no usar el cron interno del contenedor, puedes
> usar el **Scheduler de Easypanel** (si tu versión lo trae) o un cron externo
> que ejecute `php /var/www/html/cron/....php`. Da igual el método; lo que
> importa es que esos tres scripts corran a su hora.

---

## Paso 7 · Rodaje y primeros datos útiles

- **Día 1:** el cron llena `predictions` con las señales del día. El dashboard
  ya enseña señales reales.
- **Día 2 en adelante:** `settle` empieza a marcar aciertos y el laboratorio
  muestra acierto/ROI reales.
- **Semana 2–3:** con volumen, el ranking del laboratorio empieza a significar
  algo. Antes de eso, trátalo como calentamiento.
- **Lunes:** `evolve` mete las primeras estrategias propuestas por la IA a
  competir. Las verás aparecer en el laboratorio con la etiqueta IA.

> Recuerda: para que el **ROI** sea real necesitas guardar la cuota en
> `predictions.odds`. Sin cuota, `settle` asume 1.90 (aproximado). Cuando
> quieras, añadimos la captura de cuotas desde el endpoint `/odds` de
> API-Football.

---

## Problemas típicos y solución rápida

| Síntoma | Causa probable | Solución |
|---|---|---|
| El sitio no abre | puerto/dominio mal expuesto | revisa que el servicio expone el 80 y el dominio apunta bien |
| `api.php` da error 500 | credenciales de BD | revisa las variables `DB_*` en Environment |
| Todas las ligas "sin fixtures" | no hay partidos hoy en esas ligas | prueba otro día; revisa IDs de liga en `leagues` |
| Cron no ejecuta | variables no visibles para cron | el entrypoint las vuelca a /etc/environment; redeploy tras cambiarlas |
| Se agota la cuota API | demasiadas ligas activas | baja ligas activas o sube el cache; el budget corta a 90/día |
| El laboratorio solo muestra demo | aún no hay picks liquidados | espera a que `settle` corra tras los primeros partidos |

---

## Resumen del ciclo diario, ya en marcha

```
08:15  ingest_v2  →  partidos del día + todas las estrategias puntúan
       (durante el día se juegan los partidos)
01:30  settle     →  resultados + acierto + ROI por estrategia
lunes 03:00 evolve →  la IA propone variaciones, poda las malas, corona campeona
```

El dashboard (`/`) muestra las señales de la **campeona**. El laboratorio
(`/lab.html`) muestra el campeonato entre estrategias.

---

## Aviso

Herramienta de análisis informativo. No es consejo de apuesta ni garantía de
resultado. Juego responsable en España: FEJAR · autoprohibición RGIAJ.
