# --------------------------------------------
#       STAGE 1.1: Build JS with node
# --------------------------------------------

FROM node:24-alpine AS node
WORKDIR /app

# install dependencies (only copy package lock here to use docker caching)
COPY ["package.json", "package-lock.json", "./"]
RUN npm ci

# copy project data
COPY ["vite.config.js", "postcss.config.cjs", "tailwind.config.cjs", "./"]
COPY ["./resources/js/", "./resources/js/"]
COPY ["./resources/css/", "./resources/css/"]
COPY ["./resources/views/", "./resources/views/"]
COPY ["./modules", "./modules"]

# build project
RUN npm run build

# --------------------------------------------
#    STAGE 1.2: Setup PHP and Dependencies
# --------------------------------------------

FROM phpswoole/swoole:6.2-php8.5-alpine AS swoole
LABEL maintainer="Stocks"

# use workdir from swoole container
WORKDIR /app

# install php extensions: MariaDB, Redis (cache, sessions, queue), signals and sockets for Octane,
# gmp for the encryption of web push (works without, but slower)
RUN apk add --no-cache gmp \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers gmp-dev \
    && docker-php-ext-install bcmath pdo_mysql pcntl sockets gmp \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# install composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# copy relevant project data
COPY ["composer.json", "composer.lock", "artisan", "./"]
COPY ["./app", "./app"]
COPY ["./bootstrap", "./bootstrap"]
COPY ["./config", "./config"]
COPY ["./database", "./database"]
COPY ["./lang", "./lang"]
COPY ["./modules", "./modules"]
COPY ["./public", "./public"]
COPY ["./resources/css", "./resources/css"]
COPY ["./resources/views", "./resources/views"]
COPY ["./routes", "./routes"]
COPY ["./storage", "./storage"]

# install dependencies (without development packages like faker and telescope)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# get data from previous build
COPY --from=node ["/app/public/build/", "./public/build/"]

# fix storage folder
RUN mkdir -p /app/storage/logs
RUN chmod -R 777 ./storage

# defaults, overwritten by the environment of docker-compose.yaml
ENV OCTANE_MAX_REQUESTS=500
ENV OCTANE_WORKERS="auto"

EXPOSE 8000

# migrations and the module sync run on every start, so an update only needs a new image
CMD php artisan migrate --force && php artisan stocks:sync && php artisan octane:start --server=swoole --host="0.0.0.0" --workers=${OCTANE_WORKERS} --max-requests=${OCTANE_MAX_REQUESTS}

HEALTHCHECK --interval=30s --timeout=30s --start-period=5s --retries=3 CMD php artisan octane:status
