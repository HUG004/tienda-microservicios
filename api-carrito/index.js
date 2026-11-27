const express = require('express');
const { Pool } = require('pg');
const jwt = require('jsonwebtoken');
const axios = require('axios');
const fs = require('fs');

const app = express();
app.use(express.json());

const pool = new Pool({
    host: process.env.DB_HOST || 'postgres',
    port: process.env.DB_PORT || 5432,
    database: process.env.CARRITO_DB_NAME,
    user: process.env.CARRITO_DB_USER,
    password: process.env.CARRITO_DB_PASSWORD
});

const auth = (req, res, next) => {
    const isInternal = req.headers['x-internal-token'] === process.env.INTERNAL_API_TOKEN;
    if (isInternal) {
        req.isInternal = true;
        return next();
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

// Función auxiliar para obtener el carrito completo con datos de otras APIs
async function obtenerCarritoCompleto(usuario_id) {
    const carritoRes = await pool.query('SELECT id FROM carritos WHERE usuario_id = $1', [usuario_id]);
    if (carritoRes.rows.length === 0) {
        return { usuario_id: Number(usuario_id), productos: [], cantidad_total: 0, subtotal: "0.00" };
    }

    const carritoId = carritoRes.rows[0].id;
    const detalles = await pool.query('SELECT producto_id, cantidad FROM carrito_detalles WHERE carrito_id = $1', [carritoId]);
    
    let productos = [];
    let subtotalTotal = 0;
    let cantidadTotal = 0;

    for (let detalle of detalles.rows) {
        try {
            // Consulta HTTP a la API de Productos de tu compañero
            const prodRes = await axios.get(`${process.env.PRODUCTOS_API_URL}/api/productos/${detalle.producto_id}`);
            const prodData = prodRes.data.datos;
            const precio = parseFloat(prodData.precio);
            const subtotal = precio * detalle.cantidad;

            productos.push({
                producto_id: Number(detalle.producto_id),
                nombre: prodData.nombre,
                precio_unitario: prodData.precio,
                cantidad: Number(detalle.cantidad),
                subtotal: subtotal.toFixed(2)
            });
            subtotalTotal += subtotal;
            cantidadTotal += detalle.cantidad;
        } catch (error) {
            console.error(`Error consultando producto ${detalle.producto_id}`, error.message);
        }
    }

    return { usuario_id: Number(usuario_id), productos, cantidad_total: cantidadTotal, subtotal: subtotalTotal.toFixed(2) };
}

app.get('/health', (req, res) => res.json({ status: 'ok', servicio: 'api-carrito' }));
app.get('/ready', async (req, res) => {
    try { await pool.query('SELECT 1'); res.json({ status: 'ready', servicio: 'api-carrito' }); }
    catch (e) { res.status(503).json({ status: 'not_ready', servicio: 'api-carrito' }); }
});

app.get('/api/carrito/:usuario_id', auth, async (req, res) => {
    const { usuario_id } = req.params;
    if (!req.isInternal && req.usuario.sub !== usuario_id) return res.status(403).json({ error: { codigo: "ACCESO_DENEGADO", mensaje: "No es tu carrito" } });

    try {
        const carritoCompleto = await obtenerCarritoCompleto(usuario_id);
        res.json({ mensaje: carritoCompleto.productos.length > 0 ? "Carrito encontrado" : "Carrito vacio", datos: carritoCompleto });
    } catch (error) { res.status(500).json({ error: { codigo: "ERROR_INTERNO", mensaje: error.message } }); }
});

app.post('/api/carrito/:usuario_id/agregar', auth, async (req, res) => {
    const { usuario_id } = req.params;
    const { producto_id, cantidad } = req.body;
    if (!req.isInternal && req.usuario.sub !== usuario_id) return res.status(403).json({ error: { codigo: "ACCESO_DENEGADO", mensaje: "No autorizado" } });

    try {
        // 1. Validar stock en Inventario
        const invRes = await axios.get(`${process.env.INVENTARIO_API_URL}/api/inventario/${producto_id}`);
        if (invRes.data.datos.stock_disponible < cantidad) {
            return res.status(409).json({ error: { codigo: "STOCK_INSUFICIENTE", mensaje: "No hay suficiente stock" } });
        }

        // 2. Lógica DB Carrito
        const client = await pool.connect();
        try {
            await client.query('BEGIN');
            let carrito = await client.query('SELECT id FROM carritos WHERE usuario_id = $1', [usuario_id]);
            let carritoId = carrito.rows.length === 0 
                ? (await client.query('INSERT INTO carritos (usuario_id) VALUES ($1) RETURNING id', [usuario_id])).rows[0].id
                : carrito.rows[0].id;

            await client.query(`
                INSERT INTO carrito_detalles (carrito_id, producto_id, cantidad) VALUES ($1, $2, $3)
                ON CONFLICT (carrito_id, producto_id) DO UPDATE SET cantidad = carrito_detalles.cantidad + EXCLUDED.cantidad
            `, [carritoId, producto_id, cantidad]);
            await client.query('COMMIT');
        } catch (e) { await client.query('ROLLBACK'); throw e; } finally { client.release(); }

        // 3. Devolver carrito completo
        const carritoCompleto = await obtenerCarritoCompleto(usuario_id);
        res.json({ mensaje: "Producto agregado al carrito", datos: carritoCompleto });
    } catch (error) { res.status(500).json({ error: { codigo: "ERROR_INTERNO", mensaje: error.message } }); }
});

// Actualizar cantidad
app.patch('/api/carrito/:usuario_id/productos/:producto_id', auth, async (req, res) => {
    const { usuario_id, producto_id } = req.params;
    const { cantidad } = req.body;
    if (!req.isInternal && req.usuario.sub !== usuario_id) return res.status(403).json({ error: { codigo: "ACCESO_DENEGADO", mensaje: "No autorizado" } });
    if (cantidad <= 0) return res.status(400).json({ error: { codigo: "DATOS_INVALIDOS", mensaje: "Cantidad debe ser mayor a 0" } });

    try {
        const carrito = await pool.query('SELECT id FROM carritos WHERE usuario_id = $1', [usuario_id]);
        if (carrito.rows.length > 0) {
            await pool.query('UPDATE carrito_detalles SET cantidad = $1 WHERE carrito_id = $2 AND producto_id = $3', [cantidad, carrito.rows[0].id, producto_id]);
        }
        const carritoCompleto = await obtenerCarritoCompleto(usuario_id);
        res.json({ mensaje: "Cantidad actualizada correctamente", datos: carritoCompleto });
    } catch (error) { res.status(500).json({ error: { codigo: "ERROR_INTERNO", mensaje: error.message } }); }
});

// Eliminar un producto y Vaciar (similares)
app.delete('/api/carrito/:usuario_id/productos/:producto_id', auth, async (req, res) => {
    const { usuario_id, producto_id } = req.params;
    if (!req.isInternal && req.usuario.sub !== usuario_id) return res.status(403).json({ error: { codigo: "ACCESO_DENEGADO", mensaje: "No autorizado" } });
    try {
        const carrito = await pool.query('SELECT id FROM carritos WHERE usuario_id = $1', [usuario_id]);
        if (carrito.rows.length > 0) await pool.query('DELETE FROM carrito_detalles WHERE carrito_id = $1 AND producto_id = $2', [carrito.rows[0].id, producto_id]);
        res.json({ mensaje: "Producto eliminado del carrito", datos: await obtenerCarritoCompleto(usuario_id) });
    } catch (error) { res.status(500).json({ error: { codigo: "ERROR_INTERNO", mensaje: error.message } }); }
});

app.delete('/api/carrito/:usuario_id/vaciar', auth, async (req, res) => {
    const { usuario_id } = req.params;
    if (!req.isInternal && req.usuario.sub !== usuario_id) return res.status(403).json({ error: { codigo: "ACCESO_DENEGADO", mensaje: "No autorizado" } });
    try {
        const carrito = await pool.query('SELECT id FROM carritos WHERE usuario_id = $1', [usuario_id]);
        if (carrito.rows.length > 0) await pool.query('DELETE FROM carrito_detalles WHERE carrito_id = $1', [carrito.rows[0].id]);
        res.json({ mensaje: "Carrito vaciado correctamente", datos: await obtenerCarritoCompleto(usuario_id) });
    } catch (error) { res.status(500).json({ error: { codigo: "ERROR_INTERNO", mensaje: error.message } }); }
});

app.listen(8005, '0.0.0.0', () => console.log('Carrito API en puerto 8005'));