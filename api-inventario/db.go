package main

import (
	"context"
	_ "embed"
	"log"
	"net"
	"net/url"
	"sync/atomic"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"
)

//go:embed db/init.sql
var initSQL string

type servidor struct {
	cfg       config
	db        *pgxpool.Pool
	productos *clienteProductos
	migrado   atomic.Bool
}

func nuevoPool() (*pgxpool.Pool, error) {
	u := url.URL{
		Scheme:   "postgres",
		User:     url.UserPassword(requerida("DB_USER"), requerida("DB_PASSWORD")),
		Host:     net.JoinHostPort(getenv("DB_HOST", "postgres"), getenv("DB_PORT", "5432")),
		Path:     "/" + requerida("DB_NAME"),
		RawQuery: "sslmode=disable",
	}
	cfg, err := pgxpool.ParseConfig(u.String())
	if err != nil {
		return nil, err
	}
	cfg.MaxConns = 10
	return pgxpool.NewWithConfig(context.Background(), cfg)
}

// Aplica db/init.sql (idempotente) en cuanto la base esté disponible.
func (s *servidor) iniciarMigracion() {
	go func() {
		for {
			ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
			err := s.migrar(ctx)
			cancel()
			if err == nil {
				s.migrado.Store(true)
				log.Println("migracion de inventario aplicada")
				return
			}
			log.Printf("migracion pendiente, reintentando: %v", err)
			time.Sleep(3 * time.Second)
		}
	}()
}

func (s *servidor) migrar(ctx context.Context) error {
	tx, err := s.db.Begin(ctx)
	if err != nil {
		return err
	}
	defer tx.Rollback(ctx)
	if _, err := tx.Exec(ctx, "SELECT pg_advisory_xact_lock(-1)"); err != nil {
		return err
	}
	if _, err := tx.Exec(ctx, initSQL); err != nil {
		return err
	}
	return tx.Commit(ctx)
}

const sqlExpirar = `
WITH vencidas AS (
	UPDATE reservas SET estado = 'expirada'
	WHERE estado = 'pendiente' AND expira_en < NOW()
	RETURNING producto_id, cantidad
), sumas AS (
	SELECT producto_id, SUM(cantidad)::int AS total FROM vencidas GROUP BY producto_id
)
UPDATE inventario i
SET stock_reservado = i.stock_reservado - s.total, actualizado_en = NOW()
FROM sumas s
WHERE i.producto_id = s.producto_id`

// Pasa a "expirada" las reservas pendientes vencidas y devuelve su stock.
func (s *servidor) expirarVencidas(ctx context.Context) error {
	tx, err := s.db.Begin(ctx)
	if err != nil {
		return err
	}
	defer tx.Rollback(ctx)
	var obtenido bool
	if err := tx.QueryRow(ctx, "SELECT pg_try_advisory_xact_lock(-2)").Scan(&obtenido); err != nil {
		return err
	}
	if !obtenido {
		return nil
	}
	if _, err := tx.Exec(ctx,
		`SELECT id FROM reservas WHERE estado = 'pendiente' AND expira_en < NOW() ORDER BY id FOR UPDATE`); err != nil {
		return err
	}
	if _, err := tx.Exec(ctx, sqlExpirar); err != nil {
		return err
	}
	return tx.Commit(ctx)
}

func (s *servidor) expirarSilencioso(ctx context.Context) {
	if err := s.expirarVencidas(ctx); err != nil {
		log.Printf("no se pudieron expirar reservas: %v", err)
	}
}

func (s *servidor) iniciarExpiracion() {
	go func() {
		t := time.NewTicker(30 * time.Second)
		for range t.C {
			if !s.migrado.Load() {
				continue
			}
			ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
			s.expirarSilencioso(ctx)
			cancel()
		}
	}()
}