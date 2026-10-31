# Two images from one file (see compose.yaml): `app` runs PHP-FPM and the worker, `web` is nginx serving public/.
FROM php:8.3-fpm-alpine AS app
WORKDIR /srv/bookmarks
COPY . .
# The data volume inherits this ownership on first start; everything else stays read-only for the web user.
RUN mkdir -p data && chown www-data:www-data data
ENV BM_CONFIG=/srv/bookmarks/docker/config.php
USER www-data

FROM nginx:1.27-alpine AS web
COPY public /srv/bookmarks/public
COPY deploy/nginx-bookmarks-security.conf /etc/nginx/bookmarks-security.conf
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
