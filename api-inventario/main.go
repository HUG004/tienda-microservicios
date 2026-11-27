package main

import (
	"net"
	"net/http"
	"os"
	"time"

	"github.com/gin-gonic/gin"
)

const servicio = "api-inventario"

func getenv(clave, defecto string) string {
	if v := os.Getenv(clave); v != "" {
		return v
	}
	return defecto
}

func main() {
	r := gin.Default()

	r.GET("/health", func(c *gin.Context) {
		c.JSON(http.StatusOK, gin.H{"status": "ok", "servicio": servicio})
	})

	r.GET("/ready", func(c *gin.Context) {
		addr := net.JoinHostPort(getenv("DB_HOST", "postgres"), getenv("DB_PORT", "5432"))
		conn, err := net.DialTimeout("tcp", addr, 2*time.Second)
		if err != nil {
			c.JSON(http.StatusServiceUnavailable, gin.H{"status": "not_ready", "servicio": servicio})
			return
		}
		conn.Close()
		c.JSON(http.StatusOK, gin.H{"status": "ready", "servicio": servicio})
	})

	r.Run("0.0.0.0:" + getenv("PORT", "8004"))
}