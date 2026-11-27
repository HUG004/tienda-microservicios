
#!/usr/bin/env bash

set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cd "$ROOT"

if [[ ! -f .env ]]; then
    echo "ERROR: No existe el archivo .env"
    exit 1
fi

ARCHIVOS_SQL=(
    "api-productos/db/init.sql"
    "api-inventario/db/init.sql"
    "database/postgres/03_envios.sql"
    "database/postgres/06_carrito.sql"
    "database/postgres/07_pagos.sql"
)

echo "===== VERIFICANDO ARCHIVOS SQL ====="

for archivo in "${ARCHIVOS_SQL[@]}"; do
    if [[ ! -s "$archivo" ]]; then
        echo "ERROR: Archivo faltante o vacio: $archivo"
        exit 1
    fi

    echo "[OK] $archivo"
done

echo
echo "===== INICIANDO BASES DE DATOS ====="

docker compose up -d --wait postgres mongodb

migrar_sql() {
    local servicio="$1"
    local prefijo="$2"
    local archivo="$3"

    echo
    echo "===== MIGRANDO $servicio ====="

    docker compose exec -T postgres \
        bash -ec '
            prefijo="$1"

            nombre="${prefijo}_DB_NAME"
            usuario="${prefijo}_DB_USER"
            password="${prefijo}_DB_PASSWORD"

            export PGPASSWORD="${!password}"

            psql \
                -X \
                -h 127.0.0.1 \
                -U "${!usuario}" \
                -d "${!nombre}" \
                -v ON_ERROR_STOP=1 \
                --single-transaction \
                -f -
        ' bash "$prefijo" < "$archivo"

    echo "[OK] Migracion de $servicio"
}

migrar_sql \
    "Productos" \
    "PRODUCTOS" \
    "api-productos/db/init.sql"

migrar_sql \
    "Inventario" \
    "INVENTARIO" \
    "api-inventario/db/init.sql"

migrar_sql \
    "Envios" \
    "ENVIOS" \
    "database/postgres/03_envios.sql"

migrar_sql \
    "Carrito" \
    "CARRITO" \
    "database/postgres/06_carrito.sql"

migrar_sql \
    "Pagos" \
    "PAGOS" \
    "database/postgres/07_pagos.sql"

echo
echo "===== MIGRANDO USUARIOS ====="

docker compose run \
    --rm \
    --no-deps \
    api-usuarios \
    php scripts/migrate.php

echo
echo "===== MIGRANDO PEDIDOS ====="

docker compose run \
    --rm \
    --no-deps \
    api-pedidos \
    php scripts/migrate.php

echo
echo "===== MIGRACIONES COMPLETADAS ====="
