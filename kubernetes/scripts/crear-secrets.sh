#!/usr/bin/env bash

set -Eeuo pipefail

# Obtener la raiz del proyecto.
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

cd "$ROOT_DIR"

NAMESPACE="tienda"

echo "======================================"
echo " CREACION DE SECRETS DE KUBERNETES"
echo "======================================"

# Verificar archivos locales.
if [[ ! -f .env ]]; then
    echo "[ERROR] No existe el archivo .env"
    exit 1
fi

if [[ ! -f secrets/jwt_private.pem ]]; then
    echo "[ERROR] No existe la clave privada RSA"
    exit 1
fi

if [[ ! -f secrets/jwt_public.pem ]]; then
    echo "[ERROR] No existe la clave publica RSA"
    exit 1
fi

# Evitar desplegar accidentalmente en otro cluster.
CONTEXTO="$(kubectl config current-context)"

if [[ "$CONTEXTO" != "docker-desktop" ]]; then
    echo "[ERROR] Contexto inesperado: $CONTEXTO"
    exit 1
fi

# Cargar variables de entorno locales.
set -a
source .env
set +a

# Validar variables obligatorias.
VARIABLES=(
    POSTGRES_USER
    POSTGRES_PASSWORD
    USUARIOS_DB_PASSWORD
    PRODUCTOS_DB_PASSWORD
    ENVIOS_DB_PASSWORD
    INVENTARIO_DB_PASSWORD
    CARRITO_DB_PASSWORD
    PEDIDOS_DB_PASSWORD
    PAGOS_DB_PASSWORD
    MONGO_INITDB_ROOT_USERNAME
    MONGO_INITDB_ROOT_PASSWORD
    MONGO_DB
    MONGO_APP_USER
    MONGO_APP_PASSWORD
    INTERNAL_API_TOKEN
)

for VARIABLE in "${VARIABLES[@]}"; do
    VALOR="${!VARIABLE:-}"

    if [[ -z "$VALOR" || "$VALOR" == CAMBIAR_* ]]; then
        echo "[ERROR] Variable invalida: $VARIABLE"
        exit 1
    fi
done

# Crear namespace si no existe.
kubectl apply -f kubernetes/namespace.yaml

# Funcion para crear o actualizar Secrets.
crear_secret() {
    local NOMBRE="$1"
    shift

    kubectl create secret generic "$NOMBRE" \
        --namespace="$NAMESPACE" \
        "$@" \
        --dry-run=client \
        -o yaml |
        kubectl apply --server-side \
            --field-manager=tienda-secrets \
            -f -
}

# Credenciales administrativas de PostgreSQL.
crear_secret postgres-secret \
    --from-literal=POSTGRES_USER="$POSTGRES_USER" \
    --from-literal=POSTGRES_PASSWORD="$POSTGRES_PASSWORD"

# Credenciales independientes.
crear_secret usuarios-db-secret \
    --from-literal=DB_PASSWORD="$USUARIOS_DB_PASSWORD"

crear_secret productos-db-secret \
    --from-literal=DB_PASSWORD="$PRODUCTOS_DB_PASSWORD"

crear_secret envios-db-secret \
    --from-literal=DB_PASSWORD="$ENVIOS_DB_PASSWORD"

crear_secret inventario-db-secret \
    --from-literal=DB_PASSWORD="$INVENTARIO_DB_PASSWORD"

crear_secret carrito-db-secret \
    --from-literal=DB_PASSWORD="$CARRITO_DB_PASSWORD"

crear_secret pedidos-db-secret \
    --from-literal=DB_PASSWORD="$PEDIDOS_DB_PASSWORD"

crear_secret pagos-db-secret \
    --from-literal=DB_PASSWORD="$PAGOS_DB_PASSWORD"

# Credenciales de MongoDB.
crear_secret mongodb-secret \
    --from-literal=MONGO_INITDB_ROOT_USERNAME="$MONGO_INITDB_ROOT_USERNAME" \
    --from-literal=MONGO_INITDB_ROOT_PASSWORD="$MONGO_INITDB_ROOT_PASSWORD" \
    --from-literal=MONGO_APP_PASSWORD="$MONGO_APP_PASSWORD"

# Construir URI de MongoDB escapando credenciales.
MONGO_URI="$(python3 - <<'PY'
import os
from urllib.parse import quote

usuario = quote(os.environ["MONGO_APP_USER"], safe="")
password = quote(os.environ["MONGO_APP_PASSWORD"], safe="")
database = quote(os.environ["MONGO_DB"], safe="")

print(
    f"mongodb://{usuario}:{password}"
    f"@mongodb:27017/{database}"
    f"?authSource={database}"
)
PY
)"

crear_secret resenas-db-secret \
    --from-literal=MONGO_URI="$MONGO_URI"

# Token interno de los microservicios.
crear_secret internal-api-secret \
    --from-literal=INTERNAL_API_TOKEN="$INTERNAL_API_TOKEN"

# Clave privada: exclusiva de API Usuarios.
crear_secret jwt-private-secret \
    --from-file=jwt_private.pem=secrets/jwt_private.pem

# Clave publica: verificacion de tokens.
crear_secret jwt-public-secret \
    --from-file=jwt_public.pem=secrets/jwt_public.pem

echo "======================================"
echo " Secrets configurados correctamente"
echo "======================================"

kubectl get secrets -n "$NAMESPACE"