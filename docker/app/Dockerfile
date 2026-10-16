# Build the fit-tool Go binary (decodes Garmin .FIT files to JSON).
FROM --platform=$BUILDPLATFORM golang:1.25-alpine AS fit-tool
ARG TARGETARCH
WORKDIR /build
COPY docker/fit-tool/go.mod docker/fit-tool/go.sum ./
RUN go mod download
COPY docker/fit-tool/ ./
RUN CGO_ENABLED=0 GOOS=linux GOARCH=${TARGETARCH} go build -ldflags="-s -w" -o /out/fit-tool .

FROM dunglas/frankenphp:1-php8.5-alpine

WORKDIR /var/www

# Run in production by default for all self-hosted instances.
ENV APP_ENV=prod
ENV APP_DEBUG=0

# hadolint ignore=DL3018
RUN apk add --no-cache \
  bash \
  curl \
  file \
  tzdata

RUN set -eux; \
	install-php-extensions \
        bcmath \
        ctype \
        curl \
        dom \
        fileinfo \
        gd \
        intl \
        mbstring \
        opcache \
        pdo \
        pdo_sqlite \
        phar \
        session \
        simplexml \
        tokenizer \
        xml \
        xmlreader \
        xmlwriter \
        pcntl \
        zstd;

COPY docker/app/config/php.ini ${PHP_INI_DIR}/php.ini
COPY docker/app/config/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/app/config/snippets/ /etc/frankenphp/snippets/
COPY docker/app/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# health-sync: fetches Garmin Connect data, runs in its own container from this same image
# (command "health-sync"). Installed before the application, so the layer survives code changes.
COPY health-sync/requirements.txt /opt/health-sync/requirements.txt
RUN apk add --no-cache python3 \
    && python3 -m venv /opt/health-sync/venv \
    && /opt/health-sync/venv/bin/pip install --no-cache-dir --disable-pip-version-check -r /opt/health-sync/requirements.txt

# Add application
COPY . /var/www/
RUN mv /var/www/health-sync/healthsync /opt/health-sync/healthsync && rm -Rf /var/www/health-sync
RUN touch /var/www/.env

# A build from a plain git checkout (e.g. on a homelab) has no vendor/ and no country boundaries yet,
# the release pipeline prepares those before building. Prepare them here when they are missing.
# hadolint ignore=DL3018
RUN if [ ! -f vendor/autoload.php ]; then \
      apk add --no-cache --virtual .composer-deps git unzip \
      && { composer install --no-dev --prefer-dist --no-scripts --no-interaction --optimize-autoloader \
           || composer install --no-dev --prefer-dist --no-scripts --no-interaction --optimize-autoloader; } \
      && apk del .composer-deps; \
    fi
RUN if [ ! -f resources/countries/index.json ]; then \
      php bin/console app:geography:build-countries-asset \
      && rm -rf var/cache var/*.geojson; \
    fi
RUN rm -Rf docker
RUN mkdir -p /var/www/var/cache/dev
RUN mkdir -p /var/www/var/log

# Install Shoutrrr.
ARG TARGETARCH
ENV TARGETARCH=${TARGETARCH}
ARG SHOUTRRR_VERSION="0.22.0"

RUN if [ "${TARGETARCH}" = "arm64" ]; then \
      curl -fL -o shoutrrr.tar.gz "https://github.com/nicholas-fedor/shoutrrr/releases/download/v$SHOUTRRR_VERSION/shoutrrr_linux_arm64v8_${SHOUTRRR_VERSION}.tar.gz"; \
    elif [ "${TARGETARCH}" = "amd64" ]; then \
      curl -fL -o shoutrrr.tar.gz "https://github.com/nicholas-fedor/shoutrrr/releases/download/v$SHOUTRRR_VERSION/shoutrrr_linux_amd64_${SHOUTRRR_VERSION}.tar.gz"; \
    fi
RUN tar -xzf shoutrrr.tar.gz \
    && chmod +x shoutrrr \
    && mv shoutrrr /usr/bin/shoutrrr && rm shoutrrr.tar.gz

# Install fit-tool (Garmin .FIT decoder).
COPY --from=fit-tool /out/fit-tool /usr/bin/fit-tool

ENTRYPOINT ["docker-entrypoint.sh"]
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]