#!/usr/bin/env bash

set -uo pipefail

NAMESPACE="tienda"

CORRECTAS=0
FALLIDAS=0
PF_PID=""
PF_LOG=""

limpiar() {
    if [[ -n "$PF_PID" ]]; then
        kill "$PF_PID" 2>/dev/null || true
        wait "$PF_PID" 2>/dev/null || true
        PF_PID=""
    fi

    if [[ -n "$PF_LOG" ]]; then
        rm -f "$PF_LOG"
        PF_LOG=""
    fi
}

trap limpiar EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

echo "======================================"
echo " VERIFICACION TIENDA - KUBERNETES"
echo "======================================"

if [[ "$(kubectl config current-context)" != "docker-desktop" ]]; then
    echo "[ERROR] Contexto Kubernetes incorrecto"
    exit 1
fi

if ! kubectl get namespace "$NAMESPACE" >/dev/null; then
    echo "[ERROR] Namespace no encontrado"
    exit 1
fi

echo
echo "===== NODOS ====="

kubectl get nodes || exit 1

echo
echo "===== DEPLOYMENTS ====="

kubectl get deployments -n "$NAMESPACE" || exit 1

echo
echo "===== PODS ====="

kubectl get pods -n "$NAMESPACE" || exit 1

echo
echo "===== SERVICES ====="

kubectl get services -n "$NAMESPACE" || exit 1

echo
echo "===== STATEFULSETS ====="

kubectl get statefulsets -n "$NAMESPACE" || exit 1

echo
echo "===== ALMACENAMIENTO ====="

kubectl get pvc -n "$NAMESPACE" || exit 1

echo
echo "===== JOBS ====="

kubectl get jobs -n "$NAMESPACE" || exit 1

verificar_http() {
    local nombre="$1"
    local url="$2"
    local codigo

    codigo="$(curl \
        --silent \
        --output /dev/null \
        --write-out '%{http_code}' \
        --connect-timeout 3 \
        --max-time 10 \
        "$url")" || codigo="000"

    if [[ "$codigo" == "200" ]]; then
        echo "[OK] $nombre - HTTP $codigo"
        CORRECTAS=$((CORRECTAS + 1))
    else
        echo "[ERROR] $nombre - HTTP $codigo"
        FALLIDAS=$((FALLIDAS + 1))
    fi
}

abrir_tunel() {
    local servicio="$1"
    local local_port="$2"
    local remote_port="$3"

    PF_LOG="$(mktemp)"

    kubectl port-forward \
        -n "$NAMESPACE" \
        --address 127.0.0.1 \
        "svc/$servicio" \
        "$local_port:$remote_port" \
        >"$PF_LOG" 2>&1 &

    PF_PID=$!

    for ((i=0; i<40; i++)); do
        if ! kill -0 "$PF_PID" 2>/dev/null; then
            cat "$PF_LOG"
            limpiar
            return 1
        fi

        if grep -Fq \
            "Forwarding from 127.0.0.1:$local_port" \
            "$PF_LOG"; then
            return 0
        fi

        sleep 0.5
    done

    cat "$PF_LOG"
    limpiar

    return 1
}

SERVICIOS=(
    "api-usuarios:28001:8001"
    "api-productos:28002:8002"
    "api-envios:28003:8003"
    "api-inventario:28004:8004"
    "api-carrito:28005:8005"
    "api-pedidos:28006:8006"
    "api-pagos:28007:8007"
    "api-resenas:28008:8008"
)

echo
echo "===== VERIFICACION HTTP ====="

for entrada in "${SERVICIOS[@]}"; do

    IFS=: read -r servicio local_port remote_port <<< "$entrada"

    if abrir_tunel "$servicio" "$local_port" "$remote_port"; then

        verificar_http \
            "$servicio /health" \
            "http://127.0.0.1:$local_port/health"

        verificar_http \
            "$servicio /ready" \
            "http://127.0.0.1:$local_port/ready"

        limpiar
    else
        echo "[ERROR] $servicio /health - Sin conexion"
        echo "[ERROR] $servicio /ready - Sin conexion"

        FALLIDAS=$((FALLIDAS + 2))
    fi

done

echo
echo "===== VERIFICACION FRONTEND ====="

if abrir_tunel frontend 24200 4200; then

    verificar_http \
        "Frontend" \
        "http://127.0.0.1:24200/"

    limpiar
else
    echo "[ERROR] Frontend - Sin conexion"
    FALLIDAS=$((FALLIDAS + 1))
fi

echo
echo "======================================"
echo " RESULTADOS DE VERIFICACION"
echo "======================================"

echo "Correctas: $CORRECTAS"
echo "Fallidas: $FALLIDAS"
echo "Total: $((CORRECTAS + FALLIDAS))"

if [[ "$FALLIDAS" -gt 0 ]]; then
    echo "[ERROR] Existen servicios no disponibles"
    exit 1
fi

echo "[OK] Todos los servicios estan disponibles"
