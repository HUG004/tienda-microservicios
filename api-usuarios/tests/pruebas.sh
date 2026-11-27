#!/usr/bin/env bash

set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8001}"

APROBADAS=0
FALLIDAS=0

TEMP=$(mktemp)

trap 'rm -f "$TEMP"' EXIT

SUFIJO="$(date +%s)-$$"

CORREO="prueba-${SUFIJO}@tienda.test"
CORREO2="segundo-${SUFIJO}@tienda.test"
PASSWORD="Prueba123!"

probar() {
    local nombre="$1"
    local esperado="$2"

    shift 2

    local codigo

    codigo=$(curl -sS \
        --max-time 15 \
        -o "$TEMP" \
        -w "%{http_code}" \
        "$@")

    if [[ "$codigo" == "$esperado" ]]; then

        echo "[OK] $nombre - HTTP $codigo"

        APROBADAS=$((APROBADAS + 1))

    else

        echo "[ERROR] $nombre"
        echo "Esperado: $esperado"
        echo "Recibido: $codigo"

        FALLIDAS=$((FALLIDAS + 1))

        return 1

    fi
}

echo "======================================"
echo " PRUEBAS AUTOMATIZADAS API USUARIOS"
echo "======================================"

# P01. Comprobar funcionamiento del servicio

probar "Health check" 200 \
    "$BASE_URL/health"

# P02. Comprobar disponibilidad del servicio

probar "Readiness check" 200 \
    "$BASE_URL/ready"

# P03. Registro de usuario

REGISTRO=$(printf \
    '{"nombre":"Prueba automatizada","correo":"%s","contrasena":"%s"}' \
    "$CORREO" "$PASSWORD")

probar "Registro de usuario" 201 \
    -X POST "$BASE_URL/api/usuarios/registro" \
    -H "Content-Type: application/json" \
    -d "$REGISTRO"

USUARIO_ID=$(python3 -c '
import json,sys
print(json.load(open(sys.argv[1]))["datos"]["id"])
' "$TEMP")

# P04. Correo duplicado

probar "Correo duplicado" 409 \
    -X POST "$BASE_URL/api/usuarios/registro" \
    -H "Content-Type: application/json" \
    -d "$REGISTRO"

# P05. Contrasena invalida

probar "Contrasena invalida" 400 \
    -X POST "$BASE_URL/api/usuarios/registro" \
    -H "Content-Type: application/json" \
    -d "{\"nombre\":\"Prueba\",\"correo\":\"$CORREO2\",\"contrasena\":\"123\"}"

# P06. Inicio de sesion

LOGIN=$(printf \
    '{"correo":"%s","contrasena":"%s"}' \
    "$CORREO" "$PASSWORD")

probar "Inicio de sesion" 200 \
    -X POST "$BASE_URL/api/usuarios/login" \
    -H "Content-Type: application/json" \
    -d "$LOGIN"

TOKEN=$(python3 -c '
import json,sys
print(json.load(open(sys.argv[1]))["datos"]["token"])
' "$TEMP")

# P07. Consultar perfil autenticado

probar "Perfil autenticado" 200 \
    "$BASE_URL/api/usuarios/me" \
    -H "Authorization: Bearer $TOKEN"

# P08. Consultar perfil propio por ID

probar "Perfil propio por ID" 200 \
    "$BASE_URL/api/usuarios/$USUARIO_ID" \
    -H "Authorization: Bearer $TOKEN"

# P09. Acceso sin token

probar "Acceso sin token" 401 \
    "$BASE_URL/api/usuarios/me"

# P10. Token invalido

probar "Token invalido" 401 \
    "$BASE_URL/api/usuarios/me" \
    -H "Authorization: Bearer token-invalido"

# P11. Contrasena incorrecta

probar "Contrasena incorrecta" 401 \
    -X POST "$BASE_URL/api/usuarios/login" \
    -H "Content-Type: application/json" \
    -d "{\"correo\":\"$CORREO\",\"contrasena\":\"Incorrecta\"}"

# P12. Intento de registro como administrador

probar "Registro con intento de rol admin" 201 \
    -X POST "$BASE_URL/api/usuarios/registro" \
    -H "Content-Type: application/json" \
    -d "{\"nombre\":\"Segundo usuario\",\"correo\":\"$CORREO2\",\"contrasena\":\"$PASSWORD\",\"rol\":\"admin\"}"

ROL=$(python3 -c '
import json,sys
print(json.load(open(sys.argv[1]))["datos"]["rol"])
' "$TEMP")

OTRO_ID=$(python3 -c '
import json,sys
print(json.load(open(sys.argv[1]))["datos"]["id"])
' "$TEMP")

# P13. Verificar que el rol asignado sea cliente

if [[ "$ROL" == "cliente" ]]; then

    echo "[OK] Registro publico sin privilegios"

    APROBADAS=$((APROBADAS + 1))

else

    echo "[ERROR] Asignacion de rol incorrecta"

    exit 1

fi

# P14. Intento de acceso a otro usuario

probar "Acceso a otro usuario" 403 \
    "$BASE_URL/api/usuarios/$OTRO_ID" \
    -H "Authorization: Bearer $TOKEN"

# P15. Manipulacion de JWT

TOKEN_MODIFICADO=$(python3 - "$TOKEN" <<'PY'
import sys,json,base64

header,payload,firma = sys.argv[1].split(".")

datos = json.loads(
    base64.urlsafe_b64decode(
        payload + "=" * (-len(payload) % 4)
    )
)

datos["rol"] = "admin"

nuevo = base64.urlsafe_b64encode(
    json.dumps(datos).encode()
).decode().rstrip("=")

print(f"{header}.{nuevo}.{firma}")
PY
)

probar "JWT manipulado" 401 \
    "$BASE_URL/api/usuarios/me" \
    -H "Authorization: Bearer $TOKEN_MODIFICADO"

# P16. Envio de JSON invalido

probar "JSON invalido" 400 \
    -X POST "$BASE_URL/api/usuarios/registro" \
    -H "Content-Type: application/json" \
    -d '{json-invalido'

# P17. Ruta inexistente

probar "Ruta inexistente" 404 \
    "$BASE_URL/api/inexistente"

# P18. Metodo HTTP no permitido

probar "Metodo no permitido" 405 \
    "$BASE_URL/api/usuarios/login"

# Resultados finales

echo
echo "======================================"
echo "RESULTADOS"
echo "Pruebas aprobadas: $APROBADAS"
echo "Pruebas fallidas: $FALLIDAS"
echo "======================================"

if [[ "$FALLIDAS" -gt 0 ]]; then
    exit 1
fi