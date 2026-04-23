ARG PHP_VERSION=8.3
ARG FRANKENPHP_VERSION=1.8
ARG COMPOSER_VERSION=2.8
ARG BUN_VERSION="latest"
ARG APP_ENV
ARG DOCKER_FILES_BASE_PATH="docker/prod/"

FROM composer:${COMPOSER_VERSION} AS vendor

FROM node:20-bookworm-slim AS frontend

RUN apt-get update \
    && apt-get install -y --no-install-recommends git ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY package.json package-lock.json ./

RUN npm ci

COPY . .

ENV NODE_OPTIONS=--max-old-space-size=4096 \
    VITE_SKIP_CHECKER=1 \
    VITE_BUILD_NO_SOURCEMAP=1

RUN npm run build

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-builder-php${PHP_VERSION} AS upstream

COPY --from=caddy:builder /usr/bin/xcaddy /usr/bin/xcaddy

RUN CGO_ENABLED=1 \
    XCADDY_SETCAP=1 \
    XCADDY_GO_BUILD_FLAGS="-ldflags='-w -s' -tags=nobadger,nomysql,nopgx" \
    CGO_CFLAGS=$(php-config --includes) \
    CGO_LDFLAGS="$(php-config --ldflags) $(php-config --libs)" \
    xcaddy build v2.10.0 \
        --output /usr/local/bin/frankenphp \
        --with github.com/dunglas/frankenphp=./ \
        --with github.com/dunglas/frankenphp/caddy=./caddy/ \
        --with github.com/dunglas/caddy-cbrotli

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION} AS base

COPY --from=upstream /usr/local/bin/frankenphp /usr/local/bin/frankenphp

LABEL maintainer="solidtime <hello@solidtime.io>"
LABEL org.opencontainers.image.title="solidtime"
LABEL org.opencontainers.image.description="solidtime is a modern open source timetracker for freelancers and agencies"
LABEL org.opencontainers.image.source="https://github.com/solidtime-io/solidtime"
LABEL org.opencontainers.image.licenses="AGPL"

ARG WWWUSER=1000
ARG WWWGROUP=1000
ARG TZ=UTC
ARG APP_DIR=/var/www/html
ARG APP_ENV
ARG APP_HOST
ARG DOCKER_FILES_BASE_PATH

ENV DEBIAN_FRONTEND=noninteractive \
    TERM=xterm-color \
    OCTANE_SERVER=frankenphp \
    TZ=${TZ} \
    USER=octane \
    ROOT=${APP_DIR} \
    APP_ENV=${APP_ENV} \
    COMPOSER_FUND=0 \
    COMPOSER_MAX_PARALLEL_HTTP=24 \
    XDG_CONFIG_HOME=${APP_DIR}/.config \
    XDG_DATA_HOME=${APP_DIR}/.data \
    SERVER_NAME=${APP_HOST}

WORKDIR ${ROOT}

SHELL ["/bin/bash", "-eou", "pipefail", "-c"]

RUN ln -snf /usr/share/zoneinfo/${TZ} /etc/localtime \
    && echo ${TZ} > /etc/timezone

RUN apt-get update; \
    apt-get upgrade -yqq; \
    apt-get install -yqq --no-install-recommends --show-progress \
    apt-utils \
    curl \
    wget \
    vim \
    git \
    ncdu \
    procps \
    unzip \
    ca-certificates \
    supervisor \
    libsodium-dev \
    libbrotli-dev \
    # Install PHP extensions (included with dunglas/frankenphp)
    && install-php-extensions \
    bz2 \
    pcntl \
    mbstring \
    bcmath \
    sockets \
    pgsql \
    pdo_pgsql \
    opcache \
    exif \
    pdo_mysql \
    zip \
    uv \
    vips \
    intl \
    gd \
    redis \
    rdkafka \
    memcached \
    igbinary \
    ldap \
    && apt-get -y autoremove \
    && apt-get clean \
    && docker-php-source delete \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* \
    && rm /var/log/lastlog /var/log/faillog

RUN arch="$(uname -m)" \
    && case "$arch" in \
    armhf) _cronic_fname='supercronic-linux-arm' ;; \
    aarch64) _cronic_fname='supercronic-linux-arm64' ;; \
    x86_64) _cronic_fname='supercronic-linux-amd64' ;; \
    x86) _cronic_fname='supercronic-linux-386' ;; \
    *) echo >&2 "error: unsupported architecture: $arch"; exit 1 ;; \
    esac \
    && wget -q "https://github.com/aptible/supercronic/releases/download/v0.2.29/${_cronic_fname}" \
    -O /usr/bin/supercronic \
    && chmod +x /usr/bin/supercronic \
    && mkdir -p /etc/supercronic \
    && echo "*/1 * * * * php ${ROOT}/artisan schedule:run --no-interaction" > /etc/supercronic/laravel

RUN userdel --remove --force www-data \
    && groupadd --force -g ${WWWGROUP} ${USER} \
    && useradd -ms /bin/bash --no-log-init --no-user-group -g ${WWWGROUP} -u ${WWWUSER} ${USER} \
    && setcap -r /usr/local/bin/frankenphp

RUN chown -R ${USER}:${USER} ${ROOT} /var/{log,run} \
    && chmod -R a+rw ${ROOT} /var/{log,run}

RUN cp ${PHP_INI_DIR}/php.ini-production ${PHP_INI_DIR}/php.ini

USER ${USER}

COPY --link --chown=${WWWUSER}:${WWWUSER} --from=vendor /usr/bin/composer /usr/bin/composer

COPY --link --chown=${WWWUSER}:${WWWUSER} ${DOCKER_FILES_BASE_PATH}deployment/supervisord.conf /etc/
COPY --link --chown=${WWWUSER}:${WWWUSER} ${DOCKER_FILES_BASE_PATH}deployment/octane/FrankenPHP/supervisord.frankenphp.conf /etc/supervisor/conf.d/
COPY --link --chown=${WWWUSER}:${WWWUSER} ${DOCKER_FILES_BASE_PATH}deployment/supervisord.*.conf /etc/supervisor/conf.d/
COPY --link --chown=${WWWUSER}:${WWWUSER} ${DOCKER_FILES_BASE_PATH}deployment/start-container /usr/local/bin/start-container
COPY --link --chown=${WWWUSER}:${WWWUSER} ${DOCKER_FILES_BASE_PATH}deployment/healthcheck /usr/local/bin/healthcheck
COPY --link --chown=${WWWUSER}:${WWWUSER} ${DOCKER_FILES_BASE_PATH}deployment/php.ini ${PHP_INI_DIR}/conf.d/99-octane.ini

RUN chmod +x /usr/local/bin/start-container /usr/local/bin/healthcheck

###########################################

#FROM base AS common
#
#USER ${USER}
#
#COPY --link --chown=${WWWUSER}:${WWWUSER} . .
#
#RUN composer install \
#    --no-dev \
#    --no-interaction \
#    --no-autoloader \
#    --no-ansi \
#    --no-scripts \
#    --audit

###########################################
# Build frontend assets with Bun
###########################################

#FROM oven/bun:${BUN_VERSION} AS build
#
#ARG APP_ENV
#
#ENV ROOT=/var/www/html \
#    APP_ENV=${APP_ENV} \
#    NODE_ENV=${APP_ENV:-production}
#
#WORKDIR ${ROOT}
#
#COPY --link package.json bun.lock* ./
#
#RUN bun install --frozen-lockfile
#
#COPY --link . .
#COPY --link --from=common ${ROOT}/vendor vendor
#
#RUN bun run build

###########################################

#FROM common AS runner

USER ${USER}

ENV WITH_HORIZON=false \
    WITH_SCHEDULER=false \
    WITH_REVERB=false

COPY --link --chown=${WWWUSER}:${WWWUSER} . .
#COPY --link --chown=${WWWUSER}:${WWWUSER} --from=build ${ROOT}/public public

RUN mkdir -p \
    storage/framework/{sessions,views,cache,testing} \
    storage/logs \
    bootstrap/cache && chmod -R a+rw storage

# Composer post-install scripts run artisan; a temporary .env satisfies bootstrap during build.
RUN cp .env.example .env

RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-ansi \
    --prefer-dist \
    && composer clear-cache

# Octane + FrankenPHP entry (file is gitignored locally; Caddy requires it in public/).
RUN cp vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php public/frankenphp-worker.php

RUN php artisan passport:keys --force --no-interaction

COPY --link --chown=${WWWUSER}:${WWWUSER} --from=frontend /app/public/build public/build

RUN rm -f .env

# Railway and other hosts show stdout/stderr; default "single" logs only touch storage/logs.
ENV LOG_CHANNEL=stderr

EXPOSE 8000

ENTRYPOINT ["start-container"]

#HEALTHCHECK --start-period=5s --interval=2s --timeout=5s --retries=8 CMD healthcheck || exit 1
