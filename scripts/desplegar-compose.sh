
#!/usr/bin/env bash

set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cd "$ROOT"

echo "======================================"
echo " DESPLIEGUE TIENDA - DOCKER COMPOSE"
echo "======================================"

trap 'echo "[ERROR] Despliegue interrumpido en linea $LINENO"' ERR

echo
echo "===== 1. VERIFICAR REQUISITOS ====="

for comando in docker curl; do
    if ! command -v "$comando" >/dev/null 2>&1; then
        echo "[ERROR] Comando no encontrado: $comando"
        exit 1
    fi
done

docker info >/dev/null
docker compose version

ARCHIVOS=(
    ".env"
    "secrets/jwt_private.pem"
    "secrets/jwt_public.pem"
    "docker-compose.yml"
    "scripts/migrar-bases-datos.sh"
    "scripts/verificar-servicios.sh"
)

for archivo in "${ARCHIVOS[@]}"; do
    if [[ ! -s "$archivo" ]]; then
        echo "[ERROR] Archivo faltante: $archivo"
        exit 1
    fi

    echo "[OK] $archivo"
done

echo
echo "===== 2. VALIDAR DOCKER COMPOSE ====="

docker compose config --quiet

SERVICIOS=(
    postgres
    mongodb
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

CONFIGURADOS="$(docker compose config --services)"

for servicio in "${SERVICIOS[@]}"; do
    if ! grep -Fxq "$servicio" <<< "$CONFIGURADOS"; then
        echo "[ERROR] Servicio faltante: $servicio"
        exit 1
    fi

    echo "[OK] $servicio"
done

echo
echo "===== 3. CONSTRUIR IMAGENES ====="

docker compose build

echo
echo "===== 4. INICIAR BASES DE DATOS ====="

docker compose up -d \
    --wait \
    --wait-timeout 180 \
    postgres mongodb

echo
echo "===== 5. EJECUTAR MIGRACIONES ====="

bash scripts/migrar-bases-datos.sh

echo
echo "===== 6. INICIAR TODOS LOS SERVICIOS ====="

docker compose up -d \
    --wait \
    --wait-timeout 300

echo
echo "===== 7. ESTADO DE CONTENEDORES ====="

docker compose ps

echo
echo "===== 8. VERIFICAR MICROSERVICIOS ====="

bash scripts/verificar-servicios.sh

echo
echo "======================================"
echo " DESPLIEGUE COMPLETADO CORRECTAMENTE"
echo "======================================"
echo
echo "Frontend: http://localhost:4200"
echo "Usuarios: http://localhost:8001"
echo "Productos: http://localhost:8002"
echo "Envios: http://localhost:8003"
echo "Inventario: http://localhost:8004"
echo "Carrito: http://localhost:8005"
echo "Pedidos: http://localhost:8006"
echo "Pagos: http://localhost:8007"
echo "Resenas: http://localhost:8008"
