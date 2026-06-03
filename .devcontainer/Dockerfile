FROM mcr.microsoft.com/devcontainers/php:1.1.1-8.0-bullseye

ARG DEBIAN_FRONTEND=noninteractive

RUN rm -f /etc/apt/sources.list.d/yarn.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        libonig-dev \
    && docker-php-ext-install mbstring \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
