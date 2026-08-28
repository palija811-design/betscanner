#!/bin/bash
# Arranca cron en segundo plano y Apache en primer plano.
# Exporta las variables de entorno para que el cron las vea (si no, cron
# corre con un entorno vacío y perdería DB_HOST, claves, etc.).
printenv | grep -E '^(DB_|APIFOOTBALL_|ANTHROPIC_|SEASON|TZ)' > /etc/environment
cron
tail -f /var/log/signalpitch.log &
exec apache2-foreground
