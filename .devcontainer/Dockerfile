FROM mcr.microsoft.com/devcontainers/php:1.1.1-8.0-bullseye

ARG DEBIAN_FRONTEND=noninteractive

RUN rm -f /etc/apt/sources.list.d/yarn.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        libonig-dev \
    && docker-php-ext-install mbstring \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY install-mago.sh /tmp/install-mago.sh
RUN /tmp/install-mago.sh \
    && rm -f /tmp/install-mago.sh
