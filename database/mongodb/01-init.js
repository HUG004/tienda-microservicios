const nombreDB = process.env.MONGO_DB;
const usuario = process.env.MONGO_APP_USER;
const password = process.env.MONGO_APP_PASSWORD;

if (!nombreDB || !usuario || !password) {
    throw new Error("Variables de MongoDB incompletas");
}

const tiendaDB = db.getSiblingDB(nombreDB);

tiendaDB.createUser({
    user: usuario,
    pwd: password,
    roles: [
        {
            role: "readWrite",
            db: nombreDB
        }
    ]
});

tiendaDB.createCollection("resenas", {
    validator: {
        $jsonSchema: {
            bsonType: "object",
            required: [
                "producto_id",
                "usuario_id",
                "calificacion",
                "comentario",
                "creado_en"
            ],
            properties: {
                producto_id: {
                    bsonType: ["int", "long"],
                    minimum: 1
                },
                usuario_id: {
                    bsonType: ["int", "long"],
                    minimum: 1
                },
                calificacion: {
                    bsonType: "int",
                    minimum: 1,
                    maximum: 5
                },
                comentario: {
                    bsonType: "string",
                    minLength: 1,
                    maxLength: 1000
                },
                creado_en: {
                    bsonType: "date"
                }
            }
        }
    }
});

tiendaDB.resenas.createIndex({
    producto_id: 1
});

tiendaDB.resenas.createIndex(
    {
        producto_id: 1,
        usuario_id: 1
    },
    {
        unique: true
    }
);

print("Base de datos de resenas inicializada");