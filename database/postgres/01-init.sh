#!/usr/bin/env bash

set -Eeuo pipefail

crear_base_datos() {
    local nombre="$1"
    local usuario="$2"
    local password="$3"

    echo "Inicializando base de datos: $nombre"

    psql \
        --username "$POSTGRES_USER" \
        --dbname postgres \
        --set ON_ERROR_STOP=1 \
        --set db_name="$nombre" \
        --set db_user="$usuario" \
        --set db_password="$password" <<'SQL'

CREATE ROLE :"db_user"
    WITH LOGIN
    PASSWORD :'db_password'
    NOSUPERUSER
    NOCREATEDB
    NOCREATEROLE;

CREATE DATABASE :"db_name"
    OWNER :"db_user";

REVOKE ALL ON DATABASE :"db_name" FROM PUBLIC;

GRANT CONNECT, TEMPORARY
    ON DATABASE :"db_name"
    TO :"db_user";

SQL

    echo "Base de datos $nombre creada correctamente"
}

crear_base_datos \
    "$USUARIOS_DB_NAME" \
    "$USUARIOS_DB_USER" \
    "$USUARIOS_DB_PASSWORD"

crear_base_datos \
    "$PRODUCTOS_DB_NAME" \
    "$PRODUCTOS_DB_USER" \
    "$PRODUCTOS_DB_PASSWORD"

crear_base_datos \
    "$ENVIOS_DB_NAME" \
    "$ENVIOS_DB_USER" \
    "$ENVIOS_DB_PASSWORD"

crear_base_datos \
    "$INVENTARIO_DB_NAME" \
    "$INVENTARIO_DB_USER" \
    "$INVENTARIO_DB_PASSWORD"

crear_base_datos \
    "$CARRITO_DB_NAME" \
    "$CARRITO_DB_USER" \
    "$CARRITO_DB_PASSWORD"

crear_base_datos \
    "$PEDIDOS_DB_NAME" \
    "$PEDIDOS_DB_USER" \
    "$PEDIDOS_DB_PASSWORD"

crear_base_datos \
    "$PAGOS_DB_NAME" \
    "$PAGOS_DB_USER" \
    "$PAGOS_DB_PASSWORD"

echo "Inicializacion de PostgreSQL completada"