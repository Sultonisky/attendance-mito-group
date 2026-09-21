FROM node:24-bookworm AS frontend

WORKDIR /build/frontend

COPY frontend/package*.json ./
RUN npm ci

COPY frontend/ ./

ARG VITE_API_BASE_URL=https://attendance.mito.co.id/api/v1
ARG VITE_GPS_MAX_ACCURACY_METERS=100

ENV VITE_API_BASE_URL=${VITE_API_BASE_URL}
ENV VITE_GPS_MAX_ACCURACY_METERS=${VITE_GPS_MAX_ACCURACY_METERS}

RUN npm run build


FROM php:8.3-cli-bookworm AS composer

RUN apt-get update && apt-get install -y \
    unzip \
    git \
    libicu-dev \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libpq-dev \
    libonig-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install \
        gd \
        intl \
        mbstring \
        pdo_pgsql \
        zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /build

COPY backend-laravel/composer.json backend-laravel/composer.lock ./

RUN composer install \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --no-scripts


FROM php:8.3-fpm-bookworm

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y \
    nginx \
    supervisor \
    python3 \
    python3-venv \
    curl \
    libicu-dev \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libpq-dev \
    libonig-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_pgsql \
        zip

RUN pecl install redis \
    && docker-php-ext-enable redis


WORKDIR /var/www/html

COPY backend-laravel/ ./

COPY --from=composer /build/vendor ./vendor

# Vue dist at site root (no /frontend/ URL prefix). Keep Laravel index.php;
# SPA shell is stored as spa.html and served by routes/web.php.
COPY --from=frontend /build/frontend/dist/assets ./public/assets
COPY --from=frontend /build/frontend/dist/images ./public/images
COPY --from=frontend /build/frontend/dist/manifest.webmanifest ./public/manifest.webmanifest
COPY --from=frontend /build/frontend/dist/index.html ./public/spa.html

COPY ai-service /opt/ai-service

# Idempotent production bootstrap inputs (RBAC is seeded via artisan;
# these files feed outsource:import / outsource:locations:import on deploy).
COPY data/outsource_master_from_excel.csv /opt/seed-data/outsource_master.csv
COPY data/stores.json /opt/seed-data/stores.json

RUN python3 -m venv /opt/venv \
    && /opt/venv/bin/pip install --no-cache-dir \
       -r /opt/ai-service/requirements.txt

RUN mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    /var/log/supervisor \
    /run/nginx \
    /run/php

RUN chown -R www-data:www-data \
    storage \
    bootstrap/cache

RUN sed -i 's|^listen = .*|listen = 127.0.0.1:9000|' \
    /usr/local/etc/php-fpm.d/www.conf

RUN rm -f /etc/nginx/sites-enabled/default


RUN printf '%s\n' \
'server {' \
'    listen 8000;' \
'    server_name _;' \
'    root /var/www/html/public;' \
'    index index.php;' \
'    client_max_body_size 20M;' \
'' \
'    # Static Vite build output. Never fall back to PHP/HTML (breaks JS module MIME).' \
'    location ^~ /assets/ {' \
'        try_files $uri =404;' \
'        expires 7d;' \
'        add_header Cache-Control "public";' \
'    }' \
'' \
'    location = /manifest.webmanifest {' \
'        default_type application/manifest+json;' \
'        try_files $uri =404;' \
'    }' \
'' \
'    location / {' \
'        try_files $uri $uri/ /index.php?$query_string;' \
'    }' \
'' \
'    location /api/ {' \
'        try_files $uri $uri/ /index.php?$query_string;' \
'    }' \
'' \
'    location /sanctum/ {' \
'        try_files $uri $uri/ /index.php?$query_string;' \
'    }' \
'' \
'    location ~ \.php$ {' \
'        include fastcgi_params;' \
'        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;' \
'        fastcgi_param HTTP_PROXY "";' \
'        fastcgi_pass 127.0.0.1:9000;' \
'    }' \
'' \
'    location = /health {' \
'        access_log off;' \
'        default_type application/json;' \
'        return 200 "{\"status\":\"ok\",\"service\":\"hris-attendance\"}";' \
'    }' \
'}' \
> /etc/nginx/sites-available/attendance

RUN ln -s /etc/nginx/sites-available/attendance \
    /etc/nginx/sites-enabled/attendance


RUN printf '%s\n' \
'[unix_http_server]' \
'file=/run/supervisor.sock' \
'' \
'[supervisord]' \
'nodaemon=true' \
'logfile=/dev/null' \
'pidfile=/run/supervisord.pid' \
'' \
'[supervisorctl]' \
'serverurl=unix:///run/supervisor.sock' \
'' \
'[rpcinterface:supervisor]' \
'supervisor.rpcinterface_factory = supervisor.rpcinterface:make_main_rpcinterface' \
'' \
'[program:php-fpm]' \
'command=/usr/local/sbin/php-fpm --nodaemonize' \
'autostart=true' \
'autorestart=true' \
'priority=10' \
'stdout_logfile=/dev/stdout' \
'stdout_logfile_maxbytes=0' \
'stderr_logfile=/dev/stderr' \
'stderr_logfile_maxbytes=0' \
'' \
'[program:nginx]' \
'command=/usr/sbin/nginx -g "daemon off;"' \
'autostart=true' \
'autorestart=true' \
'priority=20' \
'stdout_logfile=/dev/stdout' \
'stdout_logfile_maxbytes=0' \
'stderr_logfile=/dev/stderr' \
'stderr_logfile_maxbytes=0' \
'' \
'[program:fastapi]' \
'directory=/opt/ai-service' \
'command=/opt/venv/bin/uvicorn app.main:app --host 127.0.0.1 --port 8001' \
'autostart=true' \
'autorestart=true' \
'priority=30' \
'stdout_logfile=/dev/stdout' \
'stdout_logfile_maxbytes=0' \
'stderr_logfile=/dev/stderr' \
'stderr_logfile_maxbytes=0' \
'' \

'[program:queue]' \
'directory=/var/www/html' \
'command=php artisan queue:work redis --sleep=3 --tries=3 --timeout=120' \
'autostart=true' \
'autorestart=true' \
'priority=40' \
'stdout_logfile=/dev/stdout' \
'stdout_logfile_maxbytes=0' \
'stderr_logfile=/dev/stderr' \
'stderr_logfile_maxbytes=0' \
> /etc/supervisor/conf.d/attendance.conf

EXPOSE 8000

CMD ["/usr/bin/supervisord","-c","/etc/supervisor/conf.d/attendance.conf"]
