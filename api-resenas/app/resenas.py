from datetime import datetime, timezone

from fastapi import APIRouter, Depends, Request
from fastapi.responses import JSONResponse
from pymongo.errors import DuplicateKeyError, PyMongoError

from app.auth import exigir_cliente
from app.database import db
from app.errores import ApiError
from app.productos import producto_existe

from bson import ObjectId
from pymongo import ReturnDocument

router = APIRouter(prefix="/api/resenas")

MAX_ID = 2**63
_indices_listos = False


def _ok(codigo: int, mensaje: str, datos) -> JSONResponse:
    return JSONResponse(status_code=codigo, content={"mensaje": mensaje, "datos": datos})


def _sin_bd() -> ApiError:
    return ApiError(503, "SERVICIO_NO_DISPONIBLE", "Base de datos no disponible")


def _id_valido(v) -> bool:
    return isinstance(v, int) and not isinstance(v, bool) and 0 < v < MAX_ID


def _iso(dt: datetime) -> str:
    if dt.tzinfo is not None:
        dt = dt.astimezone(timezone.utc)
    return dt.strftime("%Y-%m-%dT%H:%M:%SZ")


def _resena(doc: dict) -> dict:
    return {
        "id": str(doc["_id"]),
        "producto_id": doc["producto_id"],
        "usuario_id": doc["usuario_id"],
        "calificacion": doc["calificacion"],
        "comentario": doc["comentario"],
        "creado_en": _iso(doc["creado_en"]),
    }


async def _json(request: Request) -> dict:
    try:
        cuerpo = await request.json()
    except Exception:
        raise ApiError(400, "DATOS_INVALIDOS", "El cuerpo debe ser un JSON valido")
    if not isinstance(cuerpo, dict):
        raise ApiError(400, "DATOS_INVALIDOS", "El cuerpo debe ser un objeto JSON")
    return cuerpo


async def _asegurar_indices() -> None:
    # Garantiza el indice unico (producto_id, usuario_id) aunque Mongo no
    # estuviera listo cuando arranco la API.
    global _indices_listos
    if _indices_listos:
        return
    await db.resenas.create_index("producto_id")
    await db.resenas.create_index([("producto_id", 1), ("usuario_id", 1)], unique=True)
    _indices_listos = True


# ---------- GET /api/resenas/producto/{producto_id} ----------

@router.get("/producto/{producto_id}")
async def resenas_de_producto(producto_id: int):
    if not _id_valido(producto_id):
        raise ApiError(400, "DATOS_INVALIDOS", "producto_id debe ser un entero positivo")
    try:
        cursor = db.resenas.find({"producto_id": producto_id}).sort([("creado_en", 1), ("_id", 1)])
        docs = await cursor.to_list()
    except PyMongoError:
        raise _sin_bd()

    if not docs:
        return _ok(200, "El producto no tiene resenas", {
            "producto_id": producto_id, "promedio": 0, "total_resenas": 0, "resenas": [],
        })
    promedio = round(sum(d["calificacion"] for d in docs) / len(docs), 2)
    return _ok(200, "Resenas encontradas", {
        "producto_id": producto_id,
        "promedio": promedio,
        "total_resenas": len(docs),
        "resenas": [_resena(d) for d in docs],
    })


# ---------- POST /api/resenas ----------

@router.post("")
async def crear_resena(request: Request, usuario_token: int = Depends(exigir_cliente)):
    cuerpo = await _json(request)
    producto_id = cuerpo.get("producto_id")
    usuario_id = cuerpo.get("usuario_id")
    calificacion = cuerpo.get("calificacion")
    comentario = cuerpo.get("comentario")

    if not _id_valido(producto_id) or not _id_valido(usuario_id):
        raise ApiError(400, "DATOS_INVALIDOS", "producto_id y usuario_id deben ser enteros positivos")
    if (not isinstance(calificacion, int) or isinstance(calificacion, bool)
            or not 1 <= calificacion <= 5):
        raise ApiError(400, "CALIFICACION_INVALIDA", "La calificacion debe ser un entero entre 1 y 5")
    if not isinstance(comentario, str) or not comentario.strip() or len(comentario.strip()) > 1000:
        raise ApiError(400, "DATOS_INVALIDOS",
                       "El comentario es obligatorio y admite hasta 1000 caracteres")
    if usuario_id != usuario_token:
        raise ApiError(403, "ACCESO_DENEGADO", "No puedes publicar resenas a nombre de otro usuario")
    if not await producto_existe(producto_id):
        raise ApiError(404, "PRODUCTO_NO_ENCONTRADO", "El producto no existe")

    doc = {
        "producto_id": producto_id,
        "usuario_id": usuario_id,
        "calificacion": calificacion,
        "comentario": comentario.strip(),
        "creado_en": datetime.now(timezone.utc),
    }
    try:
        await _asegurar_indices()
        resultado = await db.resenas.insert_one(doc)
    except DuplicateKeyError:
        raise ApiError(409, "RESENA_DUPLICADA", "Ya publicaste una resena para este producto")
    except PyMongoError:
        raise _sin_bd()
    return _ok(201, "Resena creada correctamente", _resena({**doc, "_id": resultado.inserted_id}))


# ---------- POST /api/resenas/promedios ----------

@router.post("/promedios")
async def promedios(request: Request):
    cuerpo = await _json(request)
    ids = cuerpo.get("producto_ids")
    if (not isinstance(ids, list) or not 1 <= len(ids) <= 100
            or not all(_id_valido(i) for i in ids)):
        raise ApiError(400, "DATOS_INVALIDOS",
                       "producto_ids debe ser una lista de 1 a 100 enteros positivos")
    unicos = list(dict.fromkeys(ids))
    try:
        cursor = await db.resenas.aggregate([
            {"$match": {"producto_id": {"$in": unicos}}},
            {"$group": {"_id": "$producto_id", "promedio": {"$avg": "$calificacion"}, "total": {"$sum": 1}}},
        ])
        filas = await cursor.to_list()
    except PyMongoError:
        raise _sin_bd()

    por_id = {f["_id"]: f for f in filas}
    datos = []
    for pid in unicos:
        f = por_id.get(pid)
        datos.append({
            "producto_id": pid,
            "promedio": round(f["promedio"], 2) if f else 0,
            "total_resenas": f["total"] if f else 0,
        })
    return _ok(200, "Promedios encontrados", datos)

# ---------- utilidades para rutas por id ----------

def _oid(valor: str) -> ObjectId:
    if not ObjectId.is_valid(valor):
        raise ApiError(400, "DATOS_INVALIDOS", "El id de la resena no es valido")
    return ObjectId(valor)


def _no_encontrada() -> ApiError:
    return ApiError(404, "RESENA_NO_ENCONTRADA", "La resena no existe")


# ---------- GET /api/resenas/{resena_id} ----------

@router.get("/{resena_id}")
async def consultar_resena(resena_id: str):
    oid = _oid(resena_id)
    try:
        doc = await db.resenas.find_one({"_id": oid})
    except PyMongoError:
        raise _sin_bd()
    if doc is None:
        raise _no_encontrada()
    return _ok(200, "Resena encontrada", _resena(doc))


# ---------- PATCH /api/resenas/{resena_id} (solo el autor) ----------

@router.patch("/{resena_id}")
async def actualizar_resena(
    resena_id: str, request: Request, usuario_token: int = Depends(exigir_cliente)
):
    oid = _oid(resena_id)
    cuerpo = await _json(request)
    cambios = {}

    if "calificacion" in cuerpo:
        c = cuerpo["calificacion"]
        if not isinstance(c, int) or isinstance(c, bool) or not 1 <= c <= 5:
            raise ApiError(400, "CALIFICACION_INVALIDA",
                           "La calificacion debe ser un entero entre 1 y 5")
        cambios["calificacion"] = c
    if "comentario" in cuerpo:
        t = cuerpo["comentario"]
        if not isinstance(t, str) or not t.strip() or len(t.strip()) > 1000:
            raise ApiError(400, "DATOS_INVALIDOS",
                           "El comentario es obligatorio y admite hasta 1000 caracteres")
        cambios["comentario"] = t.strip()
    if not cambios:
        raise ApiError(400, "DATOS_INVALIDOS", "Indica calificacion, comentario o ambos")

    try:
        actual = await db.resenas.find_one({"_id": oid})
        if actual is None:
            raise _no_encontrada()
        if actual["usuario_id"] != usuario_token:
            raise ApiError(403, "ACCESO_DENEGADO", "Solo el autor puede modificar su resena")
        doc = await db.resenas.find_one_and_update(
            {"_id": oid, "usuario_id": usuario_token},
            {"$set": cambios},
            return_document=ReturnDocument.AFTER,
        )
    except PyMongoError:
        raise _sin_bd()
    if doc is None:
        raise _no_encontrada()
    return _ok(200, "Resena actualizada correctamente", _resena(doc))


# ---------- DELETE /api/resenas/{resena_id} (solo el autor) ----------

@router.delete("/{resena_id}")
async def eliminar_resena(resena_id: str, usuario_token: int = Depends(exigir_cliente)):
    oid = _oid(resena_id)
    try:
        actual = await db.resenas.find_one({"_id": oid})
        if actual is None:
            raise _no_encontrada()
        if actual["usuario_id"] != usuario_token:
            raise ApiError(403, "ACCESO_DENEGADO", "Solo el autor puede eliminar su resena")
        await db.resenas.delete_one({"_id": oid, "usuario_id": usuario_token})
    except PyMongoError:
        raise _sin_bd()
    return _ok(200, "Resena eliminada correctamente", {"id": resena_id})
