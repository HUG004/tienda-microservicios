#!/usr/bin/env bash

set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

NAMESPACE="tienda"

trap 'echo "[ERROR] Despliegue interrumpido en linea $LINENO"' ERR

echo "======================================"
echo " DESPLIEGUE TIENDA - KUBERNETES"
echo "======================================"

echo
echo "===== 1. VALIDAR REQUISITOS ====="

for comando in docker kubectl curl; do
    if ! command -v "$comando" >/dev/null 2>&1; then
        echo "[ERROR] Comando faltante: $comando"
        exit 1
    fi
done

docker info >/dev/null

CONTEXTO="$(kubectl config current-context)"

if [[ "$CONTEXTO" != "docker-desktop" ]]; then
    echo "[ERROR] Contexto incorrecto: $CONTEXTO"
    exit 1
fi

kubectl cluster-info
kubectl get nodes

kubectl wait \
    --for=condition=Ready \
    nodes --all \
    --timeout=120s

ARCHIVOS=(
    ".env"
    "secrets/jwt_private.pem"
    "secrets/jwt_public.pem"
    "docker-compose.yml"
    "kubernetes/scripts/crear-secrets.sh"
    "kubernetes/scripts/migrar.sh"
    "kubernetes/scripts/verificar.sh"
    "api-productos/db/init.sql"
    "api-inventario/db/init.sql"
    "database/postgres/03_envios.sql"
    "database/postgres/06_carrito.sql"
    "database/postgres/07_pagos.sql"
)

for archivo in "${ARCHIVOS[@]}"; do
    if [[ ! -s "$archivo" ]]; then
        echo "[ERROR] Archivo faltante: $archivo"
        exit 1
    fi

    echo "[OK] $archivo"
done

DIRECTORIOS=(
    api-usuarios
    api-productos
    api-envios
    api-inventario
    api-carrito
    api-pedidos
    api-pagos
    api-resenas
    frontend-tienda
)

for directorio in "${DIRECTORIOS[@]}"; do
    if [[ ! -s "$directorio/Dockerfile" ]]; then
        echo "[ERROR] Dockerfile faltante: $directorio"
        exit 1
    fi
done

echo
echo "===== 2. CONSTRUIR IMAGENES ====="

docker compose config --quiet
docker compose build

echo
echo "===== 3. CREAR NAMESPACE ====="

kubectl create namespace "$NAMESPACE" \
    --dry-run=client \
    -o yaml \
    | kubectl apply -f -

echo
echo "===== 4. APLICAR CONFIGMAPS ====="

kubectl apply -f kubernetes/configmaps/

echo
echo "===== 5. CREAR SECRETS ====="

bash kubernetes/scripts/crear-secrets.sh

echo
echo "===== 6. CONFIGURAR ALMACENAMIENTO ====="

kubectl apply -f kubernetes/storage/

echo
echo "===== 7. CREAR SERVICES ====="

kubectl apply -f kubernetes/services/

echo
echo "===== 8. INICIAR BASES DE DATOS ====="

for tipo in StatefulSet; do
    for archivo in kubernetes/deployments/*.yaml; do
        if grep -Eq "^kind: ${tipo}[[:space:]]*$" "$archivo"; then
            kubectl apply -f "$archivo"
        fi
    done
done

if [[ -d kubernetes/statefulsets ]]; then
    kubectl apply -f kubernetes/statefulsets/
fi

for base in postgres mongodb; do
    kubectl rollout status \
        "statefulset/$base" \
        -n "$NAMESPACE" \
        --timeout=300s
done

echo
echo "===== 9. EJECUTAR MIGRACIONES ====="

bash kubernetes/scripts/migrar.sh

echo
echo "===== 10. DESPLEGAR APLICACIONES ====="

for archivo in kubernetes/deployments/*.yaml; do
    if grep -Eq '^kind: Deployment[[:space:]]*$' "$archivo"; then
        kubectl apply -f "$archivo"
    fi
done

APLICACIONES=(
    api-usuarios
    api-productos
    api-envios
    api-inventario
    api-carrito
    api-pedidos
    api-pagos
    api-resenas
    frontend
)

for aplicacion in "${APLICACIONES[@]}"; do
    kubectl rollout status \
        "deployment/$aplicacion" \
        -n "$NAMESPACE" \
        --timeout=360s
done

echo
echo "===== 11. VERIFICAR DESPLIEGUE ====="

bash kubernetes/scripts/verificar.sh

echo
echo "======================================"
echo " DESPLIEGUE COMPLETADO CORRECTAMENTE"
echo "======================================"

echo
echo "Para acceder al frontend:"
echo "kubectl port-forward -n tienda svc/frontend 14200:4200"
echo "http://localhost:14200"
