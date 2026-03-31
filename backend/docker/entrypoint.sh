#!/bin/sh
set -e

if [ -f composer.json ]; then
  if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction
  fi
fi

mkdir -p var/cache var/log
mkdir -p config/jwt

if [ ! -f config/jwt/private.pem ] || [ ! -f config/jwt/public.pem ]; then
  php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction
fi

exec "$@"
