package main

import (
	"crypto/subtle"
	"net/http"
	"strconv"
	"strings"

	"github.com/gin-gonic/gin"
	"github.com/golang-jwt/jwt/v5"
)

// Rutas internas: credencial compartida en X-Internal-Token.
func (s *servidor) exigirInterno() gin.HandlerFunc {
	esperado := []byte(s.cfg.tokenInterno)
	return func(c *gin.Context) {
		recibido := []byte(c.GetHeader("X-Internal-Token"))
		if subtle.ConstantTimeCompare(recibido, esperado) != 1 {
			responderError(c, http.StatusUnauthorized, "TOKEN_INTERNO_INVALIDO",
				"Credencial interna ausente o incorrecta")
			return
		}
		c.Next()
	}
}

// Rutas de administrador: JWT RS256 con rol admin.
func (s *servidor) exigirAdmin() gin.HandlerFunc {
	return func(c *gin.Context) {
		rol, ok := s.validarJWT(c)
		if !ok {
			return
		}
		if rol != "admin" {
			responderError(c, http.StatusForbidden, "ACCESO_DENEGADO", "Permisos insuficientes")
			return
		}
		c.Next()
	}
}

func (s *servidor) validarJWT(c *gin.Context) (string, bool) {
	invalido := func() (string, bool) {
		responderError(c, http.StatusUnauthorized, "TOKEN_INVALIDO", "Token ausente, invalido o expirado")
		return "", false
	}

	partes := strings.SplitN(c.GetHeader("Authorization"), " ", 2)
	if len(partes) != 2 || !strings.EqualFold(partes[0], "Bearer") || partes[1] == "" {
		return invalido()
	}

	token, err := jwt.Parse(partes[1],
		func(t *jwt.Token) (any, error) { return s.cfg.llavePublica, nil },
		jwt.WithValidMethods([]string{"RS256"}),
		jwt.WithIssuer(s.cfg.jwtIssuer),
		jwt.WithAudience(s.cfg.jwtAudience),
		jwt.WithExpirationRequired(),
	)
	if err != nil || !token.Valid {
		return invalido()
	}
	claims, ok := token.Claims.(jwt.MapClaims)
	if !ok {
		return invalido()
	}
	sub, _ := claims["sub"].(string)
	if id, err := strconv.ParseInt(sub, 10, 64); err != nil || id <= 0 {
		return invalido()
	}
	rol, _ := claims["rol"].(string)
	if rol != "cliente" && rol != "admin" {
		return invalido()
	}
	return rol, true
}