import os

import httpx

from app.errores import ApiError


def _sin_servicio() -> ApiError:
    return ApiError(503, "SERVICIO_NO_DISPONIBLE", "No se pudo consultar el servicio de productos")


async def producto_existe(producto_id: int) -> bool:
    base = os.getenv("PRODUCTOS_API_URL")
    if not base:
        raise _sin_servicio()
    try:
        async with httpx.AsyncClient(timeout=3.0) as cliente:
            r = await cliente.get(
                f"{base.rstrip('/')}/api/productos/{producto_id}",
                headers={"Accept": "application/json"},
            )
    except httpx.HTTPError:
        raise _sin_servicio()
    if r.status_code == 200:
        return True
    if r.status_code == 404:
        return False
    raise _sin_servicio()