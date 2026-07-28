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

exec "$@"
