package main

import (
	"context"
	"errors"
	"log"
	"net/http"
	"sort"
	"strconv"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/jackc/pgx/v5"
)

const duracionReserva = 15 * time.Minute

// ---------- respuestas y errores ----------

type errAPI struct {
	status  int
	codigo  string
	mensaje string
}

func (e *errAPI) Error() string { return e.codigo }

func nuevoErr(status int, codigo, mensaje string) *errAPI {
	return &errAPI{status: status, codigo: codigo, mensaje: mensaje}
}

func responderOK(c *gin.Context, codigo int, mensaje string, datos any) {
	c.JSON(codigo, gin.H{"mensaje": mensaje, "datos": datos})
}

func responderError(c *gin.Context, codigo int, clave, mensaje string) {
	c.AbortWithStatusJSON(codigo, gin.H{"error": gin.H{"codigo": clave, "mensaje": mensaje}})
}

func (s *servidor) fallo(c *gin.Context, err error) {
	var ea *errAPI
	if errors.As(err, &ea) {
		responderError(c, ea.status, ea.codigo, ea.mensaje)
		return
	}
	log.Printf("error interno: %v", err)
	responderError(c, http.StatusInternalServerError, "ERROR_INTERNO", "Error interno del servidor")
}

func fechaISO(t time.Time) string { return t.UTC().Format("2006-01-02T15:04:05Z") }

func parseID(c *gin.Context, nombre string) (int64, bool) {
	id, err := strconv.ParseInt(c.Param(nombre), 10, 64)
	if err != nil || id <= 0 {
		responderError(c, http.StatusBadRequest, "DATOS_INVALIDOS", nombre+" no es un identificador valido")
		return 0, false
	}
	return id, true
}

// ---------- rutas ----------

func (s *servidor) router() *gin.Engine {
	r := gin.New()
	r.Use(gin.Logger(), gin.CustomRecovery(func(c *gin.Context, _ any) {
		responderError(c, http.StatusInternalServerError, "ERROR_INTERNO", "Error interno del servidor")
	}))
	r.NoRoute(func(c *gin.Context) {
		responderError(c, http.StatusNotFound, "RUTA_NO_ENCONTRADA", "Ruta no encontrada")
	})

	r.GET("/health", func(c *gin.Context) {
		c.JSON(http.StatusOK, gin.H{"status": "ok", "servicio": servicio})
	})
	r.GET("/ready", s.ready)

	api := r.Group("/api/inventario")
	interno := s.exigirInterno()
	api.POST("/reservar", interno, s.reservar)
	api.POST("/proteger-reserva", interno, s.protegerReserva)
	api.POST("/confirmar", interno, s.confirmar)
	api.POST("/liberar", interno, s.liberar)
	api.POST("/reabastecer", s.exigirAdmin(), s.reabastecer)
	api.GET("/reservas/:pedido_id", interno, s.consultarReserva)
	api.GET("/:producto_id", s.consultar)
	return r
}

func (s *servidor) ready(c *gin.Context) {
	ctx, cancel := context.WithTimeout(c.Request.Context(), 2*time.Second)
	defer cancel()
	if !s.migrado.Load() || s.db.Ping(ctx) != nil {
		c.JSON(http.StatusServiceUnavailable, gin.H{"status": "not_ready", "servicio": servicio})
		return
	}
	c.JSON(http.StatusOK, gin.H{"status": "ready", "servicio": servicio})
}

// ---------- acceso a datos ----------

type consultor interface {
	Query(ctx context.Context, sql string, args ...any) (pgx.Rows, error)
}

type reservaFila struct {
	ID         int64
	ProductoID int64
	Cantidad   int
	Estado     string
	ExpiraEn   time.Time
}

type stockFila struct {
	ProductoID int64
	Total      int
	Reservado  int
}

func reservasDe(ctx context.Context, q consultor, pedidoID int64, bloquear bool) ([]reservaFila, error) {
	sql := `SELECT id, producto_id, cantidad, estado, expira_en FROM reservas WHERE pedido_id = $1 ORDER BY id`
	if bloquear {
		sql += " FOR UPDATE"
	}
	rows, err := q.Query(ctx, sql, pedidoID)
	if err != nil {
		return nil, err
	}
	return pgx.CollectRows(rows, pgx.RowToStructByPos[reservaFila])
}

func stockDe(ctx context.Context, q consultor, ids []int64, bloquear bool) (map[int64]stockFila, error) {
	sql := `SELECT producto_id, stock_total, stock_reservado FROM inventario WHERE producto_id = ANY($1) ORDER BY producto_id`
	if bloquear {
		sql += " FOR UPDATE"
	}
	rows, err := q.Query(ctx, sql, ids)
	if err != nil {
		return nil, err
	}
	lista, err := pgx.CollectRows(rows, pgx.RowToStructByPos[stockFila])
	if err != nil {
		return nil, err
	}
	m := make(map[int64]stockFila, len(lista))
	for _, f := range lista {
		m[f.ProductoID] = f
	}
	return m, nil
}

func bloquearPedido(ctx context.Context, tx pgx.Tx, pedidoID int64) error {
	_, err := tx.Exec(ctx, "SELECT pg_advisory_xact_lock($1)", pedidoID)
	return err
}

func idsDe(filas []reservaFila) []int64 {
	ids := make([]int64, len(filas))
	for i, f := range filas {
		ids[i] = f.ProductoID
	}
	return ids
}

// ---------- GET /api/inventario/:producto_id ----------

func (s *servidor) consultar(c *gin.Context) {
	id, ok := parseID(c, "producto_id")
	if !ok {
		return
	}
	ctx := c.Request.Context()
	var total, reservado int
	err := s.db.QueryRow(ctx,
		`SELECT stock_total, stock_reservado FROM inventario WHERE producto_id = $1`, id).
		Scan(&total, &reservado)
	if errors.Is(err, pgx.ErrNoRows) {
		existe, errP := s.productos.existe(ctx, id)
		if errP != nil {
			responderError(c, http.StatusServiceUnavailable, "SERVICIO_NO_DISPONIBLE",
				"No se pudo consultar el servicio de productos")
			return
		}
		if !existe {
			responderError(c, http.StatusNotFound, "PRODUCTO_NO_ENCONTRADO", "El producto no existe")
			return
		}
		total, reservado = 0, 0
	} else if err != nil {
		s.fallo(c, err)
		return
	}
	disponible := total - reservado
	responderOK(c, http.StatusOK, "Inventario encontrado", gin.H{
		"producto_id":      id,
		"stock_total":      total,
		"stock_reservado":  reservado,
		"stock_disponible": disponible,
		"disponible":       disponible > 0,
	})
}

// ---------- POST /api/inventario/reservar ----------

type itemReserva struct {
	ProductoID int64 `json:"producto_id"`
	Cantidad   int   `json:"cantidad"`
}

type pedidoReserva struct {
	PedidoID  int64         `json:"pedido_id"`
	Productos []itemReserva `json:"productos"`
}

func validarReserva(r pedidoReserva) string {
	if r.PedidoID <= 0 {
		return "pedido_id debe ser un entero positivo"
	}
	if len(r.Productos) == 0 || len(r.Productos) > 100 {
		return "productos debe tener entre 1 y 100 elementos"
	}
	vistos := make(map[int64]bool, len(r.Productos))
	for _, it := range r.Productos {
		if it.ProductoID <= 0 {
			return "producto_id debe ser un entero positivo"
		}
		if it.Cantidad <= 0 || it.Cantidad > 100000 {
			return "cantidad debe ser un entero positivo"
		}
		if vistos[it.ProductoID] {
			return "producto_id repetido en la solicitud"
		}
		vistos[it.ProductoID] = true
	}
	return ""
}

func (s *servidor) reservar(c *gin.Context) {
	var req pedidoReserva
	if err := c.ShouldBindJSON(&req); err != nil {
		responderError(c, http.StatusBadRequest, "DATOS_INVALIDOS", "El cuerpo de la solicitud no es valido")
		return
	}
	if msg := validarReserva(req); msg != "" {
		responderError(c, http.StatusBadRequest, "DATOS_INVALIDOS", msg)
		return
	}
	ctx := c.Request.Context()
	s.expirarSilencioso(ctx)

	if err := s.verificarProductos(ctx, req.Productos); err != nil {
		s.fallo(c, err)
		return
	}
	datos, creada, err := s.reservarTx(ctx, req)
	if err != nil {
		s.fallo(c, err)
		return
	}
	codigo := http.StatusCreated
	if !creada {
		codigo = http.StatusOK
	}
	responderOK(c, codigo, "Inventario reservado correctamente", datos)
}

// Productos sin fila de inventario: se consulta a api-productos (fuera de la transaccion).
func (s *servidor) verificarProductos(ctx context.Context, items []itemReserva) error {
	ids := make([]int64, len(items))
	for i, it := range items {
		ids[i] = it.ProductoID
	}
	rows, err := s.db.Query(ctx, `SELECT producto_id FROM inventario WHERE producto_id = ANY($1)`, ids)
	if err != nil {
		return err
	}
	presentes, err := pgx.CollectRows(rows, pgx.RowTo[int64])
	if err != nil {
		return err
	}
	set := make(map[int64]bool, len(presentes))
	for _, id := range presentes {
		set[id] = true
	}
	faltan := false
	for _, id := range ids {
		if set[id] {
			continue
		}
		faltan = true
		existe, errP := s.productos.existe(ctx, id)
		if errP != nil {
			return nuevoErr(http.StatusServiceUnavailable, "SERVICIO_NO_DISPONIBLE",
				"No se pudo consultar el servicio de productos")
		}
		if !existe {
			return nuevoErr(http.StatusNotFound, "PRODUCTO_NO_ENCONTRADO",
				"El producto "+strconv.FormatInt(id, 10)+" no existe")
		}
	}
	if faltan {
		return nuevoErr(http.StatusConflict, "STOCK_INSUFICIENTE", "Hay productos sin stock registrado")
	}
	return nil
}

func mismoContenido(filas []reservaFila, items []itemReserva) bool {
	if len(filas) != len(items) {
		return false
	}
	pedido := make(map[int64]int, len(items))
	for _, it := range items {
		pedido[it.ProductoID] = it.Cantidad
	}
	for _, f := range filas {
		if cant, ok := pedido[f.ProductoID]; !ok || cant != f.Cantidad {
			return false
		}
	}
	return true
}

func respuestaReserva(pedidoID int64, filas []reservaFila) gin.H {
	reservas := make([]gin.H, 0, len(filas))
	for _, f := range filas {
		reservas = append(reservas, gin.H{
			"id": f.ID, "producto_id": f.ProductoID, "cantidad": f.Cantidad, "estado": f.Estado,
		})
	}
	return gin.H{
		"pedido_id": pedidoID,
		"estado":    filas[0].Estado,
		"reservas":  reservas,
		"expira_en": fechaISO(filas[0].ExpiraEn),
	}
}

// Devuelve (datos, creada, error). creada=false si ya existia una reserva identica.
func (s *servidor) reservarTx(ctx context.Context, req pedidoReserva) (gin.H, bool, error) {
	tx, err := s.db.Begin(ctx)
	if err != nil {
		return nil, false, err
	}
	defer tx.Rollback(ctx)

	if err := bloquearPedido(ctx, tx, req.PedidoID); err != nil {
		return nil, false, err
	}

	existentes, err := reservasDe(ctx, tx, req.PedidoID, false)
	if err != nil {
		return nil, false, err
	}
	if len(existentes) > 0 {
		if !mismoContenido(existentes, req.Productos) {
			return nil, false, nuevoErr(http.StatusConflict, "RESERVA_INCOMPATIBLE",
				"El pedido ya tiene una reserva con contenido distinto")
		}
		return respuestaReserva(req.PedidoID, existentes), false, nil
	}

	items := append([]itemReserva(nil), req.Productos...)
	sort.Slice(items, func(i, j int) bool { return items[i].ProductoID < items[j].ProductoID })
	ids := make([]int64, len(items))
	for i, it := range items {
		ids[i] = it.ProductoID
	}

	stock, err := stockDe(ctx, tx, ids, true)
	if err != nil {
		return nil, false, err
	}
	for _, it := range items {
		f, ok := stock[it.ProductoID]
		if !ok || f.Total-f.Reservado < it.Cantidad {
			return nil, false, nuevoErr(http.StatusConflict, "STOCK_INSUFICIENTE",
				"Stock insuficiente para el producto "+strconv.FormatInt(it.ProductoID, 10))
		}
	}

	expira := time.Now().UTC().Add(duracionReserva)
	reservas := make([]gin.H, 0, len(items))
	for _, it := range items {
		if _, err := tx.Exec(ctx,
			`UPDATE inventario SET stock_reservado = stock_reservado + $2, actualizado_en = NOW() WHERE producto_id = $1`,
			it.ProductoID, it.Cantidad); err != nil {
			return nil, false, err
		}
		var id int64
		if err := tx.QueryRow(ctx,
			`INSERT INTO reservas (pedido_id, producto_id, cantidad, estado, expira_en)
			 VALUES ($1, $2, $3, 'pendiente', $4) RETURNING id`,
			req.PedidoID, it.ProductoID, it.Cantidad, expira).Scan(&id); err != nil {
			return nil, false, err
		}
		reservas = append(reservas, gin.H{
			"id": id, "producto_id": it.ProductoID, "cantidad": it.Cantidad, "estado": "pendiente",
		})
	}
	if err := tx.Commit(ctx); err != nil {
		return nil, false, err
	}
	return gin.H{
		"pedido_id": req.PedidoID,
		"estado":    "pendiente",
		"reservas":  reservas,
		"expira_en": fechaISO(expira),
	}, true, nil
}

// ---------- POST /confirmar y /liberar ----------

type pedidoRef struct {
	PedidoID int64 `json:"pedido_id"`
}

func leerPedido(c *gin.Context) (int64, bool) {
	var req pedidoRef
	if err := c.ShouldBindJSON(&req); err != nil || req.PedidoID <= 0 {
		responderError(c, http.StatusBadRequest, "DATOS_INVALIDOS", "pedido_id debe ser un entero positivo")
		return 0, false
	}
	return req.PedidoID, true
}

func (s *servidor) protegerReserva(c *gin.Context) {
        id, ok := leerPedido(c)
        if !ok {
                return
        }

        ctx := c.Request.Context()
        s.expirarSilencioso(ctx)

        tx, err := s.db.Begin(ctx)
        if err != nil {
                s.fallo(c, err)
                return
        }
        defer tx.Rollback(ctx)

        if err := bloquearPedido(ctx, tx, id); err != nil {
                s.fallo(c, err)
                return
        }

        filas, err := reservasDe(ctx, tx, id, true)
        if err != nil {
                s.fallo(c, err)
                return
        }

        if len(filas) == 0 {
                s.fallo(c, nuevoErr(http.StatusNotFound,
                        "RESERVA_NO_ENCONTRADA", "El pedido no tiene reserva"))
                return
        }

        for _, f := range filas {
                switch f.Estado {
                case "pendiente":
                        if !f.ExpiraEn.After(time.Now()) {
                                s.fallo(c, nuevoErr(http.StatusConflict,
                                        "RESERVA_NO_PROTEGIBLE", "La reserva esta expirada"))
                                return
                        }
                case "procesando_pago":
                case "confirmada", "liberada", "expirada":
                        s.fallo(c, nuevoErr(http.StatusConflict,
                                "RESERVA_NO_PROTEGIBLE", "La reserva esta "+f.Estado))
                        return
                default:
                        s.fallo(c, nuevoErr(http.StatusConflict,
                                "RESERVA_NO_PROTEGIBLE", "La reserva tiene un estado no valido"))
                        return
                }
        }

        if _, err := tx.Exec(ctx,
                `UPDATE reservas
                 SET estado = 'procesando_pago'
                 WHERE pedido_id = $1 AND estado = 'pendiente'`,
                id); err != nil {
                s.fallo(c, err)
                return
        }

        if err := tx.Commit(ctx); err != nil {
                s.fallo(c, err)
                return
        }

        responderOK(c, http.StatusOK, "Reserva protegida correctamente", gin.H{
                "pedido_id": id,
                "estado":    "procesando_pago",
        })
}

func (s *servidor) confirmar(c *gin.Context) {
	id, ok := leerPedido(c)
	if !ok {
		return
	}
	ctx := c.Request.Context()
	s.expirarSilencioso(ctx)
	datos, err := s.confirmarTx(ctx, id)
	if err != nil {
		s.fallo(c, err)
		return
	}
	responderOK(c, http.StatusOK, "Reserva confirmada correctamente", datos)
}

func (s *servidor) confirmarTx(ctx context.Context, pedidoID int64) (gin.H, error) {
	tx, err := s.db.Begin(ctx)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback(ctx)

	if err := bloquearPedido(ctx, tx, pedidoID); err != nil {
		return nil, err
	}
	filas, err := reservasDe(ctx, tx, pedidoID, true)
	if err != nil {
		return nil, err
	}
	if len(filas) == 0 {
		return nil, nuevoErr(http.StatusNotFound, "RESERVA_NO_ENCONTRADA", "El pedido no tiene reserva")
	}

	hayPendiente := false
	for _, f := range filas {
		switch f.Estado {
			case "pendiente":
					if !f.ExpiraEn.After(time.Now()) {
							return nil, nuevoErr(http.StatusConflict, "RESERVA_NO_CONFIRMABLE", "La reserva expiro")
					}
					hayPendiente = true
			case "procesando_pago":
					hayPendiente = true
			case "liberada", "expirada":
					return nil, nuevoErr(http.StatusConflict, "RESERVA_NO_CONFIRMABLE", "La reserva esta "+f.Estado)
			case "confirmada":
		}
	}

	ids := idsDe(filas)
	if hayPendiente {
		if _, err := stockDe(ctx, tx, ids, true); err != nil {
			return nil, err
		}
		for _, f := range filas {
			if f.Estado != "pendiente" && f.Estado != "procesando_pago" {
        		continue
			}
			if _, err := tx.Exec(ctx,
				`UPDATE inventario
				 SET stock_total = stock_total - $2, stock_reservado = stock_reservado - $2, actualizado_en = NOW()
				 WHERE producto_id = $1`, f.ProductoID, f.Cantidad); err != nil {
				return nil, err
			}
		}
		if _, err := tx.Exec(ctx,
			`UPDATE reservas
			SET estado = 'confirmada'
			WHERE pedido_id = $1
			AND estado IN ('pendiente', 'procesando_pago')`,
			pedidoID); err != nil {
			return nil, err
		}
	}

	stock, err := stockDe(ctx, tx, ids, false)
	if err != nil {
		return nil, err
	}
	productos := make([]gin.H, 0, len(filas))
	for _, f := range filas {
		st := stock[f.ProductoID]
		productos = append(productos, gin.H{
			"producto_id":      f.ProductoID,
			"cantidad":         f.Cantidad,
			"stock_total":      st.Total,
			"stock_reservado":  st.Reservado,
			"stock_disponible": st.Total - st.Reservado,
		})
	}
	if err := tx.Commit(ctx); err != nil {
		return nil, err
	}
	return gin.H{"pedido_id": pedidoID, "estado": "confirmada", "productos": productos}, nil
}

func (s *servidor) liberar(c *gin.Context) {
	id, ok := leerPedido(c)
	if !ok {
		return
	}
	datos, err := s.liberarTx(c.Request.Context(), id)
	if err != nil {
		s.fallo(c, err)
		return
	}
	responderOK(c, http.StatusOK, "Reserva liberada correctamente", datos)
}


func (s *servidor) liberarTx(ctx context.Context, pedidoID int64) (gin.H, error) {
	tx, err := s.db.Begin(ctx)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback(ctx)

	if err := bloquearPedido(ctx, tx, pedidoID); err != nil {
		return nil, err
	}

	filas, err := reservasDe(ctx, tx, pedidoID, true)
	if err != nil {
		return nil, err
	}

	if len(filas) == 0 {
		return nil, nuevoErr(
			http.StatusNotFound,
			"RESERVA_NO_ENCONTRADA",
			"El pedido no tiene reserva",
		)
	}

	hayPendiente := false

	for _, f := range filas {
		switch f.Estado {
		case "confirmada":
			return nil, nuevoErr(
				http.StatusConflict,
				"RESERVA_NO_LIBERABLE",
				"La reserva ya fue confirmada",
			)

		case "pendiente", "procesando_pago":
			hayPendiente = true

		case "liberada":
			// Ya está liberada; no se modifica nuevamente.

		case "expirada":
			// Ya expiró y su stock reservado fue liberado.

		default:
			return nil, nuevoErr(
				http.StatusConflict,
				"RESERVA_NO_LIBERABLE",
				"La reserva tiene un estado no válido",
			)
		}
	}

	estado := filas[0].Estado

	if hayPendiente {
		if _, err := stockDe(ctx, tx, idsDe(filas), true); err != nil {
			return nil, err
		}

		for _, f := range filas {
			if f.Estado != "pendiente" && f.Estado != "procesando_pago" {
				continue
			}

			if _, err := tx.Exec(ctx,
				`UPDATE inventario
				 SET stock_reservado = stock_reservado - $2,
				     actualizado_en = NOW()
				 WHERE producto_id = $1`,
				f.ProductoID, f.Cantidad); err != nil {
				return nil, err
			}
		}

		if _, err := tx.Exec(ctx,
			`UPDATE reservas
			 SET estado = 'liberada'
			 WHERE pedido_id = $1
			   AND estado IN ('pendiente', 'procesando_pago')`,
			pedidoID); err != nil {
			return nil, err
		}

		estado = "liberada"
	}

	if err := tx.Commit(ctx); err != nil {
		return nil, err
	}

	return gin.H{
		"pedido_id": pedidoID,
		"estado":    estado,
	}, nil
}

// ---------- GET /api/inventario/reservas/:pedido_id ----------

func (s *servidor) consultarReserva(c *gin.Context) {
	id, ok := parseID(c, "pedido_id")
	if !ok {
		return
	}
	ctx := c.Request.Context()
	s.expirarSilencioso(ctx)
	filas, err := reservasDe(ctx, s.db, id, false)
	if err != nil {
		s.fallo(c, err)
		return
	}
	if len(filas) == 0 {
		responderError(c, http.StatusNotFound, "RESERVA_NO_ENCONTRADA", "El pedido no tiene reserva")
		return
	}
	productos := make([]gin.H, 0, len(filas))
	for _, f := range filas {
		productos = append(productos, gin.H{"producto_id": f.ProductoID, "cantidad": f.Cantidad})
	}
	responderOK(c, http.StatusOK, "Reserva encontrada", gin.H{
		"pedido_id": id,
		"estado":    filas[0].Estado,
		"expira_en": fechaISO(filas[0].ExpiraEn),
		"productos": productos,
	})
}

// ---------- POST /api/inventario/reabastecer (admin) ----------

type reqReabastecer struct {
	ProductoID int64 `json:"producto_id"`
	Cantidad   int   `json:"cantidad"`
}

func (s *servidor) reabastecer(c *gin.Context) {
	var req reqReabastecer
	if err := c.ShouldBindJSON(&req); err != nil || req.ProductoID <= 0 ||
		req.Cantidad <= 0 || req.Cantidad > 1000000 {
		responderError(c, http.StatusBadRequest, "DATOS_INVALIDOS",
			"producto_id y cantidad deben ser enteros positivos")
		return
	}
	ctx := c.Request.Context()
	existe, err := s.productos.existe(ctx, req.ProductoID)
	if err != nil {
		responderError(c, http.StatusServiceUnavailable, "SERVICIO_NO_DISPONIBLE",
			"No se pudo consultar el servicio de productos")
		return
	}
	if !existe {
		responderError(c, http.StatusNotFound, "PRODUCTO_NO_ENCONTRADO", "El producto no existe")
		return
	}
	var total, reservado int
	err = s.db.QueryRow(ctx,
		`INSERT INTO inventario (producto_id, stock_total) VALUES ($1, $2)
		 ON CONFLICT (producto_id) DO UPDATE
		 SET stock_total = inventario.stock_total + EXCLUDED.stock_total, actualizado_en = NOW()
		 RETURNING stock_total, stock_reservado`,
		req.ProductoID, req.Cantidad).Scan(&total, &reservado)
	if err != nil {
		s.fallo(c, err)
		return
	}
	responderOK(c, http.StatusOK, "Inventario actualizado correctamente", gin.H{
		"producto_id":      req.ProductoID,
		"stock_total":      total,
		"stock_reservado":  reservado,
		"stock_disponible": total - reservado,
	})
}