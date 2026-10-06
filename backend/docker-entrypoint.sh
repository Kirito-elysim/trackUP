#!/bin/sh
set -e

JWT_DIR="/app/config/jwt"
LOCK_DIR="$JWT_DIR/.keygen.lock"

mkdir -p "$JWT_DIR"

if [ ! -f "$JWT_DIR/private.pem" ]; then
    if mkdir "$LOCK_DIR" 2>/dev/null; then
        trap 'rmdir "$LOCK_DIR" 2>/dev/null || true' EXIT

        if [ ! -f "$JWT_DIR/private.pem" ]; then
            # Generate into temp files and rename into place atomically, so a
            # concurrent waiter never observes a partially-written key file.
            openssl genrsa -out "$JWT_DIR/private.pem.tmp" 4096
            openssl rsa -pubout -in "$JWT_DIR/private.pem.tmp" -out "$JWT_DIR/public.pem.tmp"
            chmod 644 "$JWT_DIR/private.pem.tmp" "$JWT_DIR/public.pem.tmp"
            mv "$JWT_DIR/public.pem.tmp" "$JWT_DIR/public.pem"
            mv "$JWT_DIR/private.pem.tmp" "$JWT_DIR/private.pem"
        fi
    else
        # Another container (backend or worker) is generating the keypair on the
        # shared jwt_keys volume right now — wait for it instead of racing it.
        until [ -f "$JWT_DIR/private.pem" ]; do
            sleep 1
        done
    fi
fi

# Applique les migrations Doctrine en attente avant de démarrer, pour ne plus jamais avoir besoin
# de le faire manuellement après un déploiement (voir DEPLOYMENT.md). Même mécanisme de verrou
# que pour les clés JWT ci-dessus, sur le même volume jwt_keys partagé entre backend et worker :
# le premier conteneur qui démarre migre, l'autre attend plutôt que de migrer en même temps.
MIGRATE_LOCK_DIR="$JWT_DIR/.migrate.lock"

if mkdir "$MIGRATE_LOCK_DIR" 2>/dev/null; then
    trap 'rmdir "$MIGRATE_LOCK_DIR" 2>/dev/null || true' EXIT
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    trap - EXIT
    rmdir "$MIGRATE_LOCK_DIR" 2>/dev/null || true
else
    # Idem : un autre conteneur migre déjà, on attend qu'il ait fini avant de démarrer, pour ne
    # jamais servir de requêtes sur un schéma pas encore à jour.
    while [ -d "$MIGRATE_LOCK_DIR" ]; do
        sleep 1
    done
fi

if [ "$1" = "php-fpm" ]; then
    mkdir -p /app/var/cache /app/var/log /app/var/uploads/absences /app/var/log-deliveries
    chown -R www-data:www-data /app/var
fi

exec "$@"
