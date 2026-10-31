# Dev container of the panel app: PHP 8.5, Composer 2, Node.js LTS, shellcheck
# (docs/features/specs/2026-11-01-002-dev-container-design.md §4). The PHP
# extensions the panel app and its tests need are all in the php:8.5-cli image;
# .github/workflows/panel-tests.yml lists the same ones for setup-php.
FROM composer:2 AS composer
FROM node:lts-slim AS node

FROM php:8.5-cli

RUN apt-get update \
	&& apt-get install -y --no-install-recommends ca-certificates git shellcheck unzip \
	&& rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
	&& ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# Pest needs more than the default 128M (AGENTS.md §8)
RUN echo 'memory_limit = 2G' > /usr/local/etc/php/conf.d/memory-limit.ini

RUN useradd --create-home --uid 1000 --shell /bin/bash dev
USER dev
