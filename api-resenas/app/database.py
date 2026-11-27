import os
from pymongo import AsyncMongoClient

MONGO_URI = os.getenv("MONGO_URI", "mongodb://localhost:27017")
MONGO_DB = os.getenv("MONGO_DB", "resenas_db")

client = AsyncMongoClient(MONGO_URI, serverSelectionTimeoutMS=2000)
db = client[MONGO_DB]
