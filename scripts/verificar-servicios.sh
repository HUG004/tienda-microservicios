
#!/usr/bin/env bash

set -uo pipefail

SERVICIOS=(
    "Usuarios:8001"
    "Productos:8002"
    "Envios:8003"
    "Inventario:8004"
    "Carrito:8005"
    "Pedidos:8006"
    "Pagos:8007"
    "Resenas:8008"
)

correctas=0
fallidas=0

verificar() {
    local nombre="$1"
    local url="$2"
    local codigo

    codigo=$(curl \
        --silent \
        --output /dev/null \
        --write-out '%{http_code}' \
        --connect-timeout 3 \
        --max-time 10 \
        "$url") || codigo="000"

    if [[ "$codigo" == "200" ]]; then
        echo "[OK] $nombre - HTTP $codigo"
        correctas=$((correctas + 1))
    else
        echo "[ERROR] $nombre - HTTP $codigo"
        fallidas=$((fallidas + 1))
    fi
}

echo "===== VERIFICACION DE MICROSERVICIOS ====="

for servicio in "${SERVICIOS[@]}"; do
    IFS=: read -r nombre puerto <<< "$servicio"

    verificar \
        "$nombre /health" \
        "http://localhost:$puerto/health"

    verificar \
        "$nombre /ready" \
        "http://localhost:$puerto/ready"
done

verificar "Frontend" "http://localhost:4200/"

echo
echo "===== RESULTADOS ====="
echo "Correctas: $correctas"
echo "Fallidas: $fallidas"
echo "Total: $((correctas + fallidas))"

if [[ "$fallidas" -gt 0 ]]; then
    exit 1
fi
