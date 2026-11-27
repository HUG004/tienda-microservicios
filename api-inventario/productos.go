package main

import (
	"context"
	"fmt"
	"net/http"
	"strings"
	"time"
)

type clienteProductos struct {
	base string
	http *http.Client
}

func nuevoClienteProductos(base string) *clienteProductos {
	return &clienteProductos{
		base: strings.TrimRight(base, "/"),
		http: &http.Client{Timeout: 3 * time.Second},
	}
}

// existe consulta a api-productos si el producto existe.
func (p *clienteProductos) existe(ctx context.Context, id int64) (bool, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet,
		fmt.Sprintf("%s/api/productos/%d", p.base, id), nil)
	if err != nil {
		return false, err
	}
	req.Header.Set("Accept", "application/json")
	resp, err := p.http.Do(req)
	if err != nil {
		return false, err
	}
	defer resp.Body.Close()
	switch resp.StatusCode {
	case http.StatusOK:
		return true, nil
	case http.StatusNotFound:
		return false, nil
	default:
		return false, fmt.Errorf("api-productos respondio %d", resp.StatusCode)
	}
}