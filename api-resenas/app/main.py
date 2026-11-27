from contextlib import asynccontextmanager
from fastapi import FastAPI
from fastapi.responses import JSONResponse
from app.database import client, db

SERVICIO = "api-resenas"


@asynccontextmanager
async def lifespan(app: FastAPI):
    try:
        await db.resenas.create_index("producto_id")
        await db.resenas.create_index(
            [("producto_id", 1), ("usuario_id", 1)], unique=True
        )
    except Exception:
        pass  # Mongo aun no esta listo; /ready lo va a reportar
    yield
    await client.close()


app = FastAPI(title=SERVICIO, lifespan=lifespan)


@app.get("/health")
async def health():
    return {"status": "ok", "servicio": SERVICIO}


@app.get("/ready")
async def ready():
    try:
        await client.admin.command("ping")
        return {"status": "ready", "servicio": SERVICIO}
    except Exception:
        return JSONResponse(
            status_code=503,
            content={"status": "not_ready", "servicio": SERVICIO},
        )