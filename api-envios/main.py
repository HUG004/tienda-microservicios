from fastapi import FastAPI, Header, HTTPException, Depends, Request
from fastapi.responses import JSONResponse
from fastapi.exceptions import RequestValidationError
from pydantic import BaseModel, Field
import psycopg2
import os
import random
import jwt
import requests

app = FastAPI()

# Adaptar errores de validación de FastAPI al contrato
@app.exception_handler(RequestValidationError)
async def validation_exception_handler(request: Request, exc: RequestValidationError):
    return JSONResponse(status_code=400, content={"error": {"codigo": "DATOS_INVALIDOS", "mensaje": str(exc.errors())}})

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
    expected = os.getenv("INTERNAL_API_TOKEN")
    if not expected or not x_internal_token or x_internal_token != expected: # Corrección None == None
        raise HTTPException(status_code=403, detail={"error": {"codigo": "ACCESO_DENEGADO", "mensaje": "Token interno invalido"}})

def verify_admin_or_internal(x_internal_token: str = Header(None), authorization: str = Header(None)):
    expected = os.getenv("INTERNAL_API_TOKEN")
    if expected and x_internal_token and x_internal_token == expected:
        return "internal"
    
    if not authorization or not authorization.startswith("Bearer "):
        raise HTTPException(status_code=401, detail={"error": {"codigo": "TOKEN_AUSENTE", "mensaje": "Falta token"}})
    token = authorization.split(" ")[1]
    try:
        with open("/run/secrets/jwt_public.pem", "r") as f: public_key = f.read()
        payload = jwt.decode(token, public_key, algorithms=["RS256"], issuer="api-usuarios", audience="tienda-microservicios")
        if payload.get("rol") != "admin":
            raise HTTPException(status_code=403, detail={"error": {"codigo": "ACCESO_DENEGADO", "mensaje": "Requiere admin"}}) # Cliente autenticado recibe 403
        return payload
    except jwt.ExpiredSignatureError:
        raise HTTPException(status_code=401, detail={"error": {"codigo": "TOKEN_EXPIRADO", "mensaje": "Token expirado"}})
    except jwt.InvalidTokenError:
        raise HTTPException(status_code=401, detail={"error": {"codigo": "TOKEN_INVALIDO", "mensaje": "Token invalido"}})

def verify_client_or_internal(x_internal_token: str = Header(None), authorization: str = Header(None)):
    expected = os.getenv("INTERNAL_API_TOKEN")
    if expected and x_internal_token and x_internal_token == expected:
        return "internal"
    
    if not authorization or not authorization.startswith("Bearer "):
        raise HTTPException(status_code=401, detail={"error": {"codigo": "TOKEN_AUSENTE", "mensaje": "Falta token"}})
    token = authorization.split(" ")[1]
    try:
        with open("/run/secrets/jwt_public.pem", "r") as f: public_key = f.read()
        return jwt.decode(token, public_key, algorithms=["RS256"], issuer="api-usuarios", audience="tienda-microservicios")
    except jwt.ExpiredSignatureError:
        raise HTTPException(status_code=401, detail={"error": {"codigo": "TOKEN_EXPIRADO", "mensaje": "Token expirado"}})
    except jwt.InvalidTokenError:
        raise HTTPException(status_code=401, detail={"error": {"codigo": "TOKEN_INVALIDO", "mensaje": "Token invalido"}})

@app.get("/health")
def health(): return {"status": "ok", "servicio": "api-envios"}

@app.get("/ready")
def ready():
    try:
        conn = get_db(); cur = conn.cursor()
        cur.execute("SELECT 1 FROM envios LIMIT 1")
        cur.close(); conn.close()
        return {"status": "ready", "servicio": "api-envios"}
    except: raise HTTPException(status_code=503, detail={"status": "not_ready", "servicio": "api-envios"})

class CotizarReq(BaseModel):
    codigo_postal: str = Field(..., min_length=5, max_length=5, pattern="^[0-9]{5}$")
    cantidad: int = Field(..., gt=0)
    peso: float | None = Field(None, gt=0)

@app.post("/api/envios/cotizar")
def cotizar(req: CotizarReq, user: str = Depends(verify_client_or_internal)):
    peso_num = req.peso if req.peso is not None else float(req.cantidad * 1)
    costo = 80.0 + (peso_num * 20.0)
    return {"mensaje": "Envio cotizado correctamente", "datos": {"codigo_postal": req.codigo_postal, "cantidad": req.cantidad, "peso": f"{peso_num:.3f}", "costo_envio": f"{costo:.2f}", "dias_estimados": 3, "moneda": "MXN"}}

class GenerarReq(BaseModel):
    pedido_id: int = Field(..., gt=0)
    codigo_postal: str = Field(..., min_length=5, max_length=5, pattern="^[0-9]{5}$")
    direccion: str = Field(..., min_length=5)
    cantidad: int = Field(..., gt=0)

@app.post("/api/envios/generar")
def generar(req: GenerarReq, internal: None = Depends(verify_internal)):
    conn = get_db(); cur = conn.cursor()
    try:
        # Al repetir, devolver todos los datos originales
        cur.execute("SELECT id, pedido_id, guia, codigo_postal, direccion, peso, costo_envio, dias_estimados, estado FROM envios WHERE pedido_id = %s", (req.pedido_id,))
        existente = cur.fetchone()
        if existente:
            return {"mensaje": "Guia generada correctamente", "datos": {
                "id": existente[0], "pedido_id": existente[1], "guia": existente[2], "codigo_postal": existente[3],
                "direccion": existente[4], "peso": f"{existente[5]:.3f}", "costo_envio": f"{existente[6]:.2f}",
                "dias_estimados": existente[7], "estado": existente[8]
            }}

        guia = f"ENV-{random.randint(1, 99999999):08d}"
        peso = req.cantidad * 1.0
        costo = 80.0 + (peso * 20.0)
        
        cur.execute("""
            INSERT INTO envios (pedido_id, guia, codigo_postal, direccion, peso, costo_envio, dias_estimados, estado)
            VALUES (%s, %s, %s, %s, %s, %s, %s, 'preparando') RETURNING id
        """, (req.pedido_id, guia, req.codigo_postal, req.direccion, peso, costo, 3))
        envio_id = cur.fetchone()[0]; conn.commit()
        return {"mensaje": "Guia generada correctamente", "datos": {"id": envio_id, "pedido_id": req.pedido_id, "guia": guia, "codigo_postal": req.codigo_postal, "direccion": req.direccion, "peso": f"{peso:.3f}", "costo_envio": f"{costo:.2f}", "dias_estimados": 3, "estado": "preparando"}}
    finally: cur.close(); conn.close()

@app.get("/api/envios/rastreo/{guia}")
def rastrear(guia: str):
    conn = get_db(); cur = conn.cursor()
    try:
        cur.execute("SELECT pedido_id, guia, estado, dias_estimados, actualizado_en FROM envios WHERE guia = %s", (guia,))
        envio = cur.fetchone()
        if not envio: raise HTTPException(status_code=404, detail={"error": {"codigo": "GUIA_NO_ENCONTRADA", "mensaje": "Guia no encontrada"}})
        return {"mensaje": "Envio encontrado", "datos": {"pedido_id": envio[0], "guia": envio[1], "estado": envio[2], "dias_estimados": envio[3], "actualizado_en": envio[4].isoformat() if envio[4] else None}}
    finally: cur.close(); conn.close()

class EstadoReq(BaseModel):
    estado: str

@app.patch("/api/envios/{guia}/estado")
def actualizar_estado(guia: str, req: EstadoReq, admin: dict = Depends(verify_admin_or_internal)):
    conn = get_db(); cur = conn.cursor()
    try:
        cur.execute("SELECT pedido_id, estado FROM envios WHERE guia = %s", (guia,))
        envio = cur.fetchone()
        if not envio: raise HTTPException(status_code=404, detail={"error": {"codigo": "GUIA_NO_ENCONTRADA", "mensaje": "Guia no encontrada"}})
        
        actual = envio[1]; nuevo = req.estado
        if (actual == "preparando" and nuevo != "en_transito") or (actual == "en_transito" and nuevo != "entregado") or actual == "entregado":
            raise HTTPException(status_code=409, detail={"error": {"codigo": "TRANSICION_INVALIDA", "mensaje": f"No se puede pasar de {actual} a {nuevo}"}})
        
        cur.execute("UPDATE envios SET estado = %s, actualizado_en = NOW() WHERE guia = %s", (nuevo, guia))
        conn.commit()
        
        # Notificar a Pedidos
        if nuevo == "en_transito":
            try:
                pedidos_url = os.getenv("PEDIDOS_API_URL") + f"/api/pedidos/{envio[0]}/estado"
                headers = {"X-Internal-Token": os.getenv("INTERNAL_API_TOKEN"), "Content-Type": "application/json"}
                requests.patch(pedidos_url, json={"estado": "enviado"}, headers=headers, timeout=5)
            except Exception as e: print(f"Error notificando a Pedidos: {e}")

        return {"mensaje": "Estado de envio actualizado", "datos": {"guia": guia, "estado": nuevo}}
    finally: cur.close(); conn.close()