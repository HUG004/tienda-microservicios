from fastapi import FastAPI, Header, HTTPException, Depends, Request
from fastapi.responses import JSONResponse
from pydantic import BaseModel
import psycopg2
import os
import random
import jwt

app = FastAPI()

# Manejador de errores para que cumpla el contrato {"error": {"codigo": "...", "mensaje": "..."}}
@app.exception_handler(HTTPException)
async def http_exception_handler(request: Request, exc: HTTPException):
    if isinstance(exc.detail, dict) and "error" in exc.detail:
        return JSONResponse(status_code=exc.status_code, content=exc.detail)
    return JSONResponse(status_code=exc.status_code, content={"error": {"codigo": "ERROR", "mensaje": str(exc.detail)}})

def get_db():
    return psycopg2.connect(
        host=os.getenv("DB_HOST", "postgres"), port=os.getenv("DB_PORT", 5432),
        dbname=os.getenv("ENVIOS_DB_NAME"), user=os.getenv("ENVIOS_DB_USER"), password=os.getenv("ENVIOS_DB_PASSWORD")
    )

def verify_internal(x_internal_token: str = Header(None)):
    if x_internal_token != os.getenv("INTERNAL_API_TOKEN"):
        raise HTTPException(status_code=403, detail={"error": {"codigo": "ACCESO_DENEGADO", "mensaje": "Token interno invalido"}})

def verify_admin(authorization: str = Header(None)):
    if not authorization or not authorization.startswith("Bearer "):
        raise HTTPException(status_code=401, detail={"error": {"codigo": "TOKEN_AUSENTE", "mensaje": "Falta token"}})
    token = authorization.split(" ")[1]
    try:
        with open("/run/secrets/jwt_public.pem", "r") as f:
            public_key = f.read()
        payload = jwt.decode(token, public_key, algorithms=["RS256"], issuer="api-usuarios", audience="tienda-microservicios")
        if payload.get("rol") != "admin":
            raise HTTPException(status_code=403, detail={"error": {"codigo": "ACCESO_DENEGADO", "mensaje": "Requiere admin"}})
        return payload
    except Exception as e:
        raise HTTPException(status_code=401, detail={"error": {"codigo": "TOKEN_INVALIDO", "mensaje": "Token invalido"}})

@app.get("/health")
def health(): return {"status": "ok", "servicio": "api-envios"}

@app.get("/ready")
def ready():
    try:
        conn = get_db(); conn.close()
        return {"status": "ready", "servicio": "api-envios"}
    except:
        raise HTTPException(status_code=503, detail={"status": "not_ready", "servicio": "api-envios"})

class CotizarReq(BaseModel):
    codigo_postal: str
    cantidad: int
    peso: str | None = None

@app.post("/api/envios/cotizar")
def cotizar(req: CotizarReq):
    if len(req.codigo_postal) != 5 or not req.codigo_postal.isdigit():
        raise HTTPException(status_code=400, detail={"error": {"codigo": "DATOS_INVALIDOS", "mensaje": "CP invalido"}})
    peso_num = float(req.peso) if req.peso else float(req.cantidad * 1)
    costo = 80.0 + (peso_num * 20.0)
    return {"mensaje": "Envio cotizado correctamente", "datos": {"codigo_postal": req.codigo_postal, "cantidad": req.cantidad, "peso": f"{peso_num:.3f}", "costo_envio": f"{costo:.2f}", "dias_estimados": 3, "moneda": "MXN"}}

class GenerarReq(BaseModel):
    pedido_id: int
    codigo_postal: str
    direccion: str
    cantidad: int

@app.post("/api/envios/generar")
def generar(req: GenerarReq, internal: None = Depends(verify_internal)):
    conn = get_db(); cur = conn.cursor()
    try:
        cur.execute("SELECT id, guia, estado FROM envios WHERE pedido_id = %s", (req.pedido_id,))
        existente = cur.fetchone()
        if existente:
            return {"mensaje": "Guia generada correctamente", "datos": {"pedido_id": req.pedido_id, "guia": existente[1], "estado": existente[2]}}

        guia = f"ENV-{random.randint(1, 99999999):08d}"
        peso = req.cantidad * 1.0
        costo = 80.0 + (peso * 20.0)
        
        cur.execute("""
            INSERT INTO envios (pedido_id, guia, codigo_postal, direccion, peso, costo_envio, dias_estimados, estado)
            VALUES (%s, %s, %s, %s, %s, %s, %s, 'preparando') RETURNING id
        """, (req.pedido_id, guia, req.codigo_postal, req.direccion, peso, costo, 3))
        envio_id = cur.fetchone()[0]; conn.commit()
        return {"mensaje": "Guia generada correctamente", "datos": {"id": envio_id, "pedido_id": req.pedido_id, "guia": guia, "codigo_postal": req.codigo_postal, "direccion": req.direccion, "peso": f"{peso:.3f}", "costo_envio": f"{costo:.2f}", "dias_estimados": 3, "estado": "preparando"}}
    finally:
        cur.close(); conn.close()

@app.get("/api/envios/rastreo/{guia}")
def rastrear(guia: str):
    conn = get_db(); cur = conn.cursor()
    try:
        cur.execute("SELECT pedido_id, guia, estado, dias_estimados, actualizado_en FROM envios WHERE guia = %s", (guia,))
        envio = cur.fetchone()
        if not envio:
            raise HTTPException(status_code=404, detail={"error": {"codigo": "GUIA_NO_ENCONTRADA", "mensaje": "Guia no encontrada"}})
        return {"mensaje": "Envio encontrado", "datos": {"pedido_id": envio[0], "guia": envio[1], "estado": envio[2], "dias_estimados": envio[3], "actualizado_en": envio[4].isoformat() if envio[4] else None}}
    finally:
        cur.close(); conn.close()

class EstadoReq(BaseModel):
    estado: str

@app.patch("/api/envios/{guia}/estado")
def actualizar_estado(guia: str, req: EstadoReq, admin: dict = Depends(verify_admin)):
    conn = get_db(); cur = conn.cursor()
    try:
        cur.execute("SELECT estado FROM envios WHERE guia = %s", (guia,))
        envio = cur.fetchone()
        if not envio: raise HTTPException(status_code=404, detail={"error": {"codigo": "GUIA_NO_ENCONTRADA", "mensaje": "Guia no encontrada"}})
        
        # Validar transiciones
        actual = envio[0]
        nuevo = req.estado
        if (actual == "preparando" and nuevo != "en_transito") or (actual == "en_transito" and nuevo != "entregado") or actual == "entregado":
            raise HTTPException(status_code=409, detail={"error": {"codigo": "TRANSICION_INVALIDA", "mensaje": f"No se puede pasar de {actual} a {nuevo}"}})
        
        cur.execute("UPDATE envios SET estado = %s, actualizado_en = NOW() WHERE guia = %s", (nuevo, guia))
        conn.commit()
        return {"mensaje": "Estado de envio actualizado", "datos": {"guia": guia, "estado": nuevo}}
    finally:
        cur.close(); conn.close()