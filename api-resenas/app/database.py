import os
from pathlib import Path
from urllib.parse import quote_plus

from dotenv import load_dotenv
from pymongo import AsyncMongoClient

# En local lee el .env de la raíz del repo. En Docker no existe ese archivo
# y las variables ya vienen del entorno (no se sobrescriben).
load_dotenv(Path(__file__).resolve().parents[2] / ".env")

MONGO_DB = os.getenv("MONGO_DB", "resenas_db").strip()


def construir_uri() -> str:
    uri = os.getenv("MONGO_URI")
    if uri:
        return uri
    usuario = quote_plus(os.environ["MONGO_APP_USER"].strip())
    password = quote_plus(os.environ["MONGO_APP_PASSWORD"].strip())
    host = os.getenv("MONGO_HOST")
    if host:
        puerto = "27017"  # dentro de Docker
    else:
        host = "localhost"  # local: Mongo publicado en tu máquina
        puerto = os.getenv("MONGO_PORT", "27018").strip()
    return f"mongodb://{usuario}:{password}@{host}:{puerto}/{MONGO_DB}?authSource={MONGO_DB}"


client = AsyncMongoClient(construir_uri(), serverSelectionTimeoutMS=2000)
db = client[MONGO_DB]