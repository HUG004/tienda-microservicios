package main

import (
	"crypto/rsa"
	"log"
	"net/http"
	"os"
	"time"

	"github.com/golang-jwt/jwt/v5"
)

const servicio = "api-inventario"

type config struct {
	puerto       string
	jwtIssuer    string
	jwtAudience  string
	llavePublica *rsa.PublicKey
	tokenInterno string
	productosURL string
}

func getenv(clave, defecto string) string {
	if v := os.Getenv(clave); v != "" {
		return v
	}
	return defecto
}

func requerida(clave string) string {
	v := os.Getenv(clave)
	if v == "" {
		log.Fatalf("falta la variable de entorno %s", clave)
	}
	return v
}

func cargarConfig() config {
	pem, err := os.ReadFile(requerida("JWT_PUBLIC_KEY_PATH"))
	if err != nil {
		log.Fatalf("no se pudo leer la clave publica JWT: %v", err)
	}
	llave, err := jwt.ParseRSAPublicKeyFromPEM(pem)
	if err != nil {
		log.Fatalf("clave publica JWT invalida: %v", err)
	}
	return config{
		puerto:       getenv("PORT", "8004"),
		jwtIssuer:    requerida("JWT_ISSUER"),
		jwtAudience:  requerida("JWT_AUDIENCE"),
		llavePublica: llave,
		tokenInterno: requerida("INTERNAL_API_TOKEN"),
		productosURL: requerida("PRODUCTOS_API_URL"),
	}
}

func main() {
	cfg := cargarConfig()
	pool, err := nuevoPool()
	if err != nil {
		log.Fatalf("configuracion de base de datos invalida: %v", err)
	}
	s := &servidor{cfg: cfg, db: pool, productos: nuevoClienteProductos(cfg.productosURL)}
	s.iniciarMigracion()
	s.iniciarExpiracion()

	srv := &http.Server{
		Addr:              "0.0.0.0:" + cfg.puerto,
		Handler:           s.router(),
		ReadHeaderTimeout: 5 * time.Second,
	}
	log.Printf("%s escuchando en %s", servicio, srv.Addr)
	log.Fatal(srv.ListenAndServe())
}