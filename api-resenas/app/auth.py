import os
from functools import lru_cache

import jwt
from fastapi import Header

from app.errores import ApiError


@lru_cache
def _llave_publica() -> str:
    with open(os.environ["JWT_PUBLIC_KEY_PATH"], "r") as f:
        return f.read()


def _token_invalido() -> ApiError:
    return ApiError(401, "TOKEN_INVALIDO", "Token ausente, invalido o expirado")


def exigir_cliente(authorization: str | None = Header(default=None)) -> int:
    """Valida el JWT RS256 y devuelve el id del usuario (rol cliente)."""
    if not authorization:
        raise _token_invalido()
    partes = authorization.split(" ", 1)
    if len(partes) != 2 or partes[0].lower() != "bearer" or not partes[1].strip():
        raise _token_invalido()

    try:
        llave = _llave_publica()
        issuer = os.environ["JWT_ISSUER"]
        audience = os.environ["JWT_AUDIENCE"]
    except (KeyError, OSError):
        raise ApiError(500, "ERROR_INTERNO", "Error interno del servidor")

    try:
        claims = jwt.decode(
            partes[1].strip(),
            llave,
            algorithms=["RS256"],
            issuer=issuer,
            audience=audience,
            options={"require": ["exp", "iss", "aud", "sub"]},
        )
    except jwt.PyJWTError:
        raise _token_invalido()

    sub = claims.get("sub")
    if not isinstance(sub, str) or not (sub.isascii() and sub.isdigit()) or int(sub) <= 0:
        raise _token_invalido()
    rol = claims.get("rol")
    if rol not in ("cliente", "admin"):
        raise _token_invalido()
    if rol != "cliente":
        raise ApiError(403, "ACCESO_DENEGADO", "Permisos insuficientes")
    return int(sub)