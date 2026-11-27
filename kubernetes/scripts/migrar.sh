#!/usr/bin/env bash

set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

cd "$ROOT"

NAMESPACE="tienda"

if [[ "$(kubectl config current-context)" != "docker-desktop" ]]; then
    echo "[ERROR] Contexto Kubernetes incorrecto"
    exit 1
fi

ARCHIVOS=(
    "api-productos/db/init.sql"
    "api-inventario/db/init.sql"
    "database/postgres/03_envios.sql"
    "database/postgres/06_carrito.sql"
    "database/postgres/07_pagos.sql"
)

for archivo in "${ARCHIVOS[@]}"; do
    if [[ ! -s "$archivo" ]]; then
        echo "[ERROR] Archivo SQL faltante: $archivo"
        exit 1
    fi
done

echo "===== VERIFICANDO POSTGRESQL ====="

kubectl rollout status \
    statefulset/postgres \
    -n "$NAMESPACE" \
    --timeout=180s

crear_configmap() {
    local servicio="$1"
    local archivo="$2"

    kubectl create configmap \
        "$servicio-migration-sql" \
        -n "$NAMESPACE" \
        --from-file="init.sql=$archivo" \
        --dry-run=client \
        -o yaml \
        | kubectl apply -f -
}

ejecutar_job() {
    local servicio="$1"
    local job="$servicio-migrate"

    echo
    echo "===== MIGRANDO $servicio ====="

    kubectl delete job "$job" \
        -n "$NAMESPACE" \
        --ignore-not-found=true \
        --wait=true

    kubectl apply \
        -f "kubernetes/jobs/$job.yaml"

    if ! kubectl wait \
        --for=condition=complete \
        "job/$job" \
        -n "$NAMESPACE" \
        --timeout=320s
    then
        kubectl logs \
            "job/$job" \
            -n "$NAMESPACE" \
            --all-containers=true || true

        echo "[ERROR] Fallo la migracion: $servicio"
        exit 1
    fi

    kubectl logs "job/$job" -n "$NAMESPACE"

    echo "[OK] Migracion completada: $servicio"
}

echo "===== CREANDO CONFIGMAPS SQL ====="

crear_configmap productos api-productos/db/init.sql

crear_configmap inventario api-inventario/db/init.sql

crear_configmap envios database/postgres/03_envios.sql

crear_configmap carrito database/postgres/06_carrito.sql

crear_configmap pagos database/postgres/07_pagos.sql

echo "===== EJECUTANDO MIGRACIONES ====="

for servicio in \
    usuarios \
    productos \
    inventario \
    envios \
    carrito \
    pedidos \
    pagos
do
    ejecutar_job "$servicio"
done

echo "===== RESULTADOS ====="

kubectl get jobs -n "$NAMESPACE"

echo "[OK] Migraciones completadas"
