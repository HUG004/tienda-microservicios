const express = require('express');
const { Pool } = require('pg');
const jwt = require('jsonwebtoken');
const axios = require('axios');
const fs = require('fs');
const path = require('path');

const app = express();
app.use(express.json());

// Configuración DB
const pool = new Pool({
    host: process.env.DB_HOST || 'postgres',
    port: process.env.DB_PORT || 5432,
    database: process.env.CARRITO_DB_NAME,
    user: process.env.CARRITO_DB_USER,
    password: process.env.CARRITO_DB_PASSWORD
});

// Middleware de Autenticación (JWT o Interno)
const auth = (req, res, next) => {
    const internalToken = req.headers['x-internal-token'];
    if (internalToken && internalToken === process.env.INTERNAL_API_TOKEN) {
        return next(); // Acceso interno autorizado
    }

    const authHeader = req.headers.authorization;
    if (!authHeader) return res.status(401).json({ error: { codigo: "TOKEN_AUSENTE", mensaje: "No hay token" } });

    const token = authHeader.split(' ')[1];
    try {
        const publicKey = fs.readFileSync('/run/secrets/jwt_public.pem', 'utf8');
        const decoded = jwt.verify(token, publicKey, { algorithms: ['RS256'], issuer: 'api-usuarios', audience: 'tienda-microservicios' });
        req.usuario = decoded;
        next();
    } catch (err) {
        return res.status(401).json({ error: { codigo: "TOKEN_INVALIDO", mensaje: "Token inválido" } });
    }
};

// Endpoints Health
app.get('/health', (req, res) => res.json({ status: 'ok', servicio: 'api-carrito' }));
app.get('/ready', async (req, res) => {
    try { await pool.query('SELECT 1'); res.json({ status: 'ready', servicio: 'api-carrito' }); }
    catch (e) { res.status(503).json({ status: 'not_ready', servicio: 'api-carrito' }); }
});

// GET Carrito
app.get('/api/carrito/:usuario_id', auth, async (req, res) => {
    const { usuario_id } = req.params;
    // (Validación: si no es interno, el token debe coincidir con el usuario_id)
    if (!req.headers['x-internal-token'] && req.usuario.sub !== usuario_id) {
        return res.status(403).json({ error: { codigo: "ACCESO_DENEGADO", mensaje: "No es tu carrito" } });
    }

    try {
        const carritoRes = await pool.query('SELECT id FROM carritos WHERE usuario_id = $1', [usuario_id]);
        if (carritoRes.rows.length === 0) {
            return res.json({ mensaje: "Carrito vacio", datos: { usuario_id: Number(usuario_id), productos: [], cantidad_total: 0, subtotal: "0.00" } });
        }

        const carritoId = carritoRes.rows[0].id;
        const detalles = await pool.query(`
            SELECT cd.producto_id, cd.cantidad, p.nombre, p.precio 
            FROM carrito_detalles cd
            JOIN productos p ON p.id = cd.producto_id -- Aquí simulamos el JOIN. En producción real, piden los datos a la API Productos
            WHERE cd.carrito_id = $1
        `, [carritoId]);
        
        let total = 0;
        const productos = detalles.rows.map(row => {
            const subtotal = (row.cantidad * parseFloat(row.precio)).toFixed(2);
            total += parseFloat(subtotal);
            return {
                producto_id: row.producto_id,
                nombre: row.nombre,
                precio_unitario: row.precio.toString(),
                cantidad: row.cantidad,
                subtotal: subtotal
            };
        });

        res.json({ mensaje: "Carrito encontrado", datos: { usuario_id: Number(usuario_id), productos, cantidad_total: productos.reduce((a,b)=>a+b.cantidad,0), subtotal: total.toFixed(2) } });
    } catch (error) {
        res.status(500).json({ error: { codigo: "ERROR_INTERNO", mensaje: "Error en servidor" } });
    }
});

// POST Agregar
app.post('/api/carrito/:usuario_id/agregar', auth, async (req, res) => {
    const { usuario_id } = req.params;
    const { producto_id, cantidad } = req.body;

    if (req.usuario && req.usuario.sub !== usuario_id) return res.status(403).json({ error: { codigo: "ACCESO_DENEGADO", mensaje: "No autorizado" } });

    try {
        // 1. Verificar producto y stock (Llamada HTTP a los otros servicios de tus compañeros)
        // const prod = await axios.get(`${process.env.PRODUCTOS_API_URL}/api/productos/${producto_id}`);
        // const inv = await axios.get(`${process.env.INVENTARIO_API_URL}/api/inventario/${producto_id}`);
        
        // Simulamos para evitar fallos si los compañeros no han terminado
        const precioFalso = "899.00"; 
        
        // 2. Lógica DB Carrito
        const client = await pool.connect();
        try {
            await client.query('BEGIN');
            let carrito = await client.query('SELECT id FROM carritos WHERE usuario_id = $1', [usuario_id]);
            let carritoId;
            if (carrito.rows.length === 0) {
                const nuevo = await client.query('INSERT INTO carritos (usuario_id) VALUES ($1) RETURNING id', [usuario_id]);
                carritoId = nuevo.rows[0].id;
            } else {
                carritoId = carrito.rows[0].id;
            }

            await client.query(`
                INSERT INTO carrito_detalles (carrito_id, producto_id, cantidad) 
                VALUES ($1, $2, $3)
                ON CONFLICT (carrito_id, producto_id) 
                DO UPDATE SET cantidad = carrito_detalles.cantidad + EXCLUDED.cantidad
            `, [carritoId, producto_id, cantidad]);
            
            await client.query('COMMIT');
            res.status(200).json({ mensaje: "Producto agregado al carrito", datos: { usuario_id: Number(usuario_id) } }); // En un caso real, devuelve el carrito completo
        } catch (e) {
            await client.query('ROLLBACK');
            throw e;
        } finally {
            client.release();
        }
    } catch (error) {
        res.status(500).json({ error: { codigo: "ERROR_INTERNO", mensaje: error.message } });
    }
});

// Puedes agregar los endpoints faltantes (vaciar, PATCH, DELETE) siguiendo esta misma lógica.

app.listen(8005, '0.0.0.0', () => console.log('Carrito API en puerto 8005'));