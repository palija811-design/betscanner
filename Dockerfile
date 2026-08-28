# ============================================================
#  Signal Pitch · imagen PHP 8.2 + Apache + cron
# ============================================================
FROM php:8.2-apache

# Extensiones necesarias: pdo_mysql para MariaDB
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite

# cron para los 3 trabajos programados
RUN apt-get update && apt-get install -y cron tzdata \
    && rm -rf /var/lib/apt/lists/*

# Zona horaria (los cron y logs en hora de Madrid; el código usa UTC internamente)
ENV TZ=Europe/Madrid

# La raíz web sirve /src (ahí están api.php y performance.php).
# Los paneles HTML se sirven desde /public.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html
COPY . /var/www/html

# Instala la crontab y da permisos
COPY crontab.txt /etc/cron.d/signalpitch
RUN chmod 0644 /etc/cron.d/signalpitch \
    && crontab /etc/cron.d/signalpitch \
    && touch /var/log/signalpitch.log

# Arranca cron + apache juntos
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
CMD ["/entrypoint.sh"]
