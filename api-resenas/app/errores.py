import logging

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from starlette.exceptions import HTTPException as StarletteHTTPException

log = logging.getLogger("api-resenas")


class ApiError(Exception):
    def __init__(self, status: int, codigo: str, mensaje: str):
        super().__init__(codigo)
        self.status = status
        self.codigo = codigo
        self.mensaje = mensaje


def _cuerpo(status: int, codigo: str, mensaje: str) -> JSONResponse:
    return JSONResponse(
        status_code=status,
        content={"error": {"codigo": codigo, "mensaje": mensaje}},
    )


def registrar_manejadores(app: FastAPI) -> None:
    @app.exception_handler(ApiError)
    async def _api_error(_: Request, exc: ApiError):
        return _cuerpo(exc.status, exc.codigo, exc.mensaje)

    @app.exception_handler(RequestValidationError)
    async def _validacion(_: Request, exc: RequestValidationError):
        return _cuerpo(400, "DATOS_INVALIDOS", "Los datos proporcionados no son validos")

    @app.exception_handler(StarletteHTTPException)
    async def _http(_: Request, exc: StarletteHTTPException):
        if exc.status_code == 404:
            return _cuerpo(404, "RUTA_NO_ENCONTRADA", "Ruta no encontrada")
        if exc.status_code == 405:
            return _cuerpo(405, "METODO_NO_PERMITIDO", "Metodo no permitido")
        return _cuerpo(exc.status_code, "ERROR_HTTP", "Solicitud no valida")

    @app.exception_handler(Exception)
    async def _interno(_: Request, exc: Exception):
        log.exception("error interno")
        return _cuerpo(500, "ERROR_INTERNO", "Error interno del servidor")