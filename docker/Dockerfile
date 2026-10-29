FROM composer/composer:2-bin AS composer
FROM mlocati/php-extension-installer:latest AS php-extension-installer

FROM php:8.5-cli-bookworm

COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer /composer /usr/bin/composer

RUN apt-get update && apt-get -y install git unzip --no-install-recommends && rm -rf /var/lib/apt/lists/*
RUN install-php-extensions curl yaml mbstring pcntl xmlwriter

ARG UID=1000
ARG GID=1000
RUN groupadd -g ${GID} app && useradd -m -u ${UID} -g ${GID} app && mkdir -p /home/app/.composer/cache && chown -R app:app /home/app/.composer
USER app
WORKDIR /app
