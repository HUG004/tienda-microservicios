from fastapi import FastAPI, Header, HTTPException, Depends
from pydantic import BaseModel
import psycopg2
import os
import random
import string
import jwt
import datetime

app = FastAPI()

# Conexión DB (usar pool en producción, aquí simple)
def get_db():
    return psycopg2.connect(
        host=os.getenv("DB_HOST", "postgres"),
        port=os.getenv("DB_PORT", 5432),
        dbname=os.getenv("ENVIOS_DB_NAME"),
        user=os.getenv("ENVIOS_DB_USER"),
        password=os.getenv("ENVIOS_DB_PASSWORD")
    )

def verify_internal(x_internal_token: str = Header(None)):
    if x_internal_token != os.getenv("INTERNAL_API_TOKEN"):
        raise HTTPException(status_code=403, detail={"error": {"codigo": "ACCESO_DENEGADO", "mensaje": "Token interno invalido"}})

# --- Health ---
@app.get("/health")
def health(): return {"status": "ok", "servicio": "api-envios"}

@app.get("/ready")
def ready():
    try:
        conn = get_db()
        conn.close()
        return {"status": "ready", "servicio": "api-envios"}
    except:
        raise HTTPException(status_code=503, detail={"status": "not_ready", "servicio": "api-envios"})

# --- ENDPOINTS ---
class CotizarReq(BaseModel):
    codigo_postal: str
    cantidad: int
    peso: str | None = None

@app.post("/api/envios/cotizar")
def cotizar(req: CotizarReq):
    if len(req.codigo_postal) != 5 or not req.codigo_postal.isdigit():
        raise HTTPException(status_code=400, detail={"error": {"codigo": "DATOS_INVALIDOS", "mensaje": "CP invalido"}})
    
    peso_num = float(req.peso) if req.peso else float(req.cantidad * 1)
    costo = 80.0 + (peso_num * 20.0) # Regla del contrato
    
    return {
        "mensaje": "Envio cotizado correctamente",
        "datos": {
            "codigo_postal": req.codigo_postal,
            "cantidad": req.cantidad,
            "peso": f"{peso_num:.3f}",
            "costo_envio": f"{costo:.2f}",
            "dias_estimados": 3,
            "moneda": "MXN"
        }
    }

class GenerarReq(BaseModel):
    pedido_id: int
    codigo_postal: str
    direccion: str
    cantidad: int

@app.post("/api/envios/generar")
def generar(req: GenerarReq, internal: None = Depends(verify_internal)):
    conn = get_db()
    cur = conn.cursor()
    try:
        # Verificar si ya existe
        cur.execute("SELECT id, guia, estado FROM envios WHERE pedido_id = %s", (req.pedido_id,))
        existente = cur.fetchone()
        if existente:
            return {"mensaje": "Guia generada correctamente", "datos": {"pedido_id": req.pedido_id, "guia": existente[1], "estado": existente[2]}}

        # Generar guía (ENV-00000001)
        guia = f"ENV-{random.randint(1, 99999999):08d}"
        peso = req.cantidad * 1.0
        costo = 80.0 + (peso * 20.0)
        
        cur.execute("""
            INSERT INTO envios (pedido_id, guia, codigo_postal, direccion, peso, costo_envio, dias_estimados, estado)
            VALUES (%s, %s, %s, %s, %s, %s, %s, 'preparando') RETURNING id
        """, (req.pedido_id, guia, req.codigo_postal, req.direccion, peso, costo, 3))
        envio_id = cur.fetchone()[0]
        conn.commit()
        
        return {
            "mensaje": "Guia generada correctamente",
            "datos": {"id": envio_id, "pedido_id": req.pedido_id, "guia": guia, "codigo_postal": req.codigo_postal, "direccion": req.direccion, "peso": f"{peso:.3f}", "costo_envio": f"{costo:.2f}", "dias_estimados": 3, "estado": "preparando"}
        }
    finally:
        cur.close()
        conn.close()

# GET Rastreo... (puedes agregarlo siguiendo la misma lógica)