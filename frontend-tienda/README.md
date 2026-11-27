# TechStore — Frontend (`frontend-tienda`)

## Descripción

Interfaz web de comercio electrónico tipo SPA (*Single Page Application*) desarrollada en **Angular 21**, empaquetada mediante un *build* multi-etapa con **Docker** y servida a través de **Nginx** dentro de un clúster local de **Kubernetes**.

Este módulo actúa como el punto de entrada único para el usuario (`http://localhost:4200`) y funciona como *API Gateway / Reverse Proxy* ligero para comunicar la interfaz gráfica con los **8 microservicios** del ecosistema sin exponer múltiples puertos al navegador ni generar conflictos de CORS.

---

## Tecnologías Utilizadas

* **Framework Frontend:** Angular 21 (Componentes *Standalone*, *Signals* para reactividad de estado, *TypeScript*, enrutamiento SPA)
* **Servidor Web y Proxy Inverso:** Nginx (`nginx:alpine`) escuchando en el puerto `4200`
* **Contenedorización:** Docker con `Dockerfile` *multi-stage* (`node:22-alpine` $\rightarrow$ `nginx:alpine`)
* **Orquestación:** Kubernetes (`Deployment` + `Service` tipo `NodePort`) gestionado mediante `kubectl`
* **Control de Versiones:** Git y GitHub (rama `feature/frontend`)

---

## Arquitectura

El frontend desacopla completamente la capa de presentación de la lógica de negocio distribuida en los 8 microservicios y las bases de datos del proyecto:

```text
[Navegador Usuario: http://localhost:4200]
                   │
                   ▼ (kubectl port-forward)
┌──────────────────────────────────────────────────────────┐
│  Pod Kubernetes: frontend-tienda (Puerto 4200)           │
│  ┌────────────────────────────────────────────────────┐  │
│  │  Nginx (nginx:alpine)                              │  │
│  │  ├── Rutas estáticas (/*) ──► Angular 21 SPA       │  │
│  │  └── Rutas API (/api/*)   ──► Reverse Proxy        │  │
│  └────────────────────────────────────────────────────┘  │
└──────────────────────────┬───────────────────────────────┘
                           │ Resolución dinámica vía kube-dns
        ┌──────────────────┼──────────────────┐
        ▼                  ▼                  ▼
  api-usuarios       api-productos       api-carrito ... (8 APIs)
    (:8001)            (:8002)            (:8005)
```

---

## Objetivo

Implementar la capa visual y de enrutamiento de la aplicación distribuida multi-stack, demostrando la integración en tiempo real con los ocho microservicios REST, el manejo resiliente de errores de red y su despliegue automatizado mediante contenedores orquestados con Kubernetes y `kubectl`.

---

## Estructura del Proyecto

```text
frontend-tienda/
├── src/
│   ├── app/
│   │   ├── components/       # Vistas principales (Catálogo, Carrito, Login, Registro, Pedidos, Reseñas)
│   │   ├── services/         # Clientes HTTP desacoplados para consumir cada uno de los 8 microservicios
│   │   ├── models/           # Interfaces TypeScript (Producto, Usuario, ItemCarrito, Pedido, etc.)
│   │   ├── app.routes.ts     # Definición de rutas SPA de Angular
│   │   └── app.ts            # Componente raíz y barra de navegación principal
│   ├── index.html            # Documento HTML base de la aplicación
│   └── styles.css            # Estilos globales y diseño responsivo
├── Dockerfile                # Receta de construcción multi-etapa (Node 22 -> Nginx Alpine)
├── nginx.conf                # Configuración de servidor SPA, DNS dinámico y proxy hacia los 8 microservicios
├── k8s-frontend.yaml         # Manifiesto declarativo de Kubernetes (Deployment y Service)
├── angular.json              # Configuración de compilación y rutas de salida de Angular CLI
├── package.json              # Dependencias del proyecto y scripts npm
└── README.md                 # Documentación técnica del módulo frontend
```

---

## Módulos Funcionales de la Interfaz

* **Catálogo e Inventario en Tiempo Real:** Visualización de productos con barra de búsqueda, filtrado por categorías y validación de disponibilidad de stock antes de agregar artículos.
* **Autenticación y Gestión de Usuarios:** Formularios reactivos de inicio de sesión y registro de clientes con manejo de sesión y tokens JWT.
* **Carrito de Compras Interactivo:** Agregar, actualizar cantidades y eliminar artículos con cálculo automático de subtotales y totales.
* **Flujo de Checkout, Pagos y Envíos:** Generación de órdenes de compra, selección/seguimiento de envío y procesamiento de pago conectado a sus respectivos microservicios.
* **Sistema de Reseñas:** Consulta y publicación de calificaciones y comentarios por producto.
* **Manejo Resiliente de Errores:** Alertas visuales informativas cuando alguno de los microservicios backend aún no está disponible en el clúster.

---

## Configuración de Nginx y Resolución DNS en Kubernetes

Por defecto, Nginx intenta resolver todos los nombres de host definidos en `proxy_pass` durante el arranque; si un microservicio aún no ha levantado su Pod en Kubernetes, el contenedor falla con `host not found in upstream`.

Para evitar este bloqueo en el arranque distribuido, `nginx.conf` implementa:

1. **`resolver kube-dns.kube-system.svc.cluster.local 127.0.0.11 valid=10s ipv6=off;`**: Consulta periódicamente el DNS interno del clúster de Kubernetes (y el DNS embebido de Docker como respaldo).
2. **Variables dinámicas (`set $upstream_...`)**: Obliga a Nginx a resolver la IP del microservicio en tiempo de petición HTTP y no al iniciar el contenedor.
3. **Soporte SPA (`try_files $uri $uri/ /index.html;`)**: Evita errores `404 Not Found` al recargar rutas internas de Angular.

---

## Mapa de Integración con los 8 Microservicios

Todas las peticiones HTTP en Angular utilizan rutas relativas (`/api/<recurso>`), las cuales Nginx enruta internamente dentro de la red del clúster hacia los servicios correspondientes:

| Ruta en Angular | Servicio Destino (DNS K8s) | Puerto | Integrante Responsable | Funcionalidad Principal |
| :--- | :--- | :---: | :--- | :--- |
| `/api/usuarios` | `http://api-usuarios:8001` | `8001` | Integrante 1 | Registro, login, perfiles y autenticación JWT |
| `/api/productos` | `http://api-productos:8002` | `8002` | Integrante 2 | Listado, detalle, búsqueda y categorías de productos |
| `/api/envios` | `http://api-envios:8003` | `8003` | Integrante 3 | Cálculo de tarifas, direcciones y rastreo de envíos |
| `/api/inventario` | `http://api-inventario:8004` | `8004` | Integrante 2 | Control y descuento de existencias en almacén |
| `/api/carrito` | `http://api-carrito:8005` | `8005` | Integrante 3 | Persistencia temporal de artículos seleccionados |
| `/api/pedidos` | `http://api-pedidos:8006` | `8006` | Integrante 1 | Creación, estado e historial de órdenes |
| `/api/pagos` | `http://api-pagos:8007` | `8007` | Integrante 3 | Pasarela y validación de transacciones |
| `/api/resenas` | `http://api-resenas:8008` | `8008` | Integrante 2 | Opiniones y puntuaciones de usuarios |

---

## Guía de Despliegue en Kubernetes (`kubectl`)

### Prerrequisitos

* **Docker Desktop** instalado y en ejecución con la opción **Enable Kubernetes** activa (`kubectl get nodes` en estado `Ready`).
* Repositorio clonado en local en la rama actualizada.

### Pasos de Ejecución

Ubícate dentro de la carpeta del frontend:

```bash
cd frontend-tienda
```

1. **Construir la imagen Docker local:**
   ```bash
   docker build -t frontend-tienda:v2 .
   ```

2. **Aplicar el manifiesto de Kubernetes (`Deployment` + `Service`):**
   ```bash
   kubectl apply -f k8s-frontend.yaml
   ```

3. **Comprobar que el Pod esté en ejecución (`1/1 Running`):**
   ```bash
   kubectl get pods
   kubectl get services
   ```

4. **Habilitar el acceso desde el navegador mediante `port-forward`:**
   ```bash
   kubectl port-forward service/frontend-tienda-service 4200:4200
   ```

5. **Abrir la aplicación:**
   Ingresa en tu navegador a **`http://localhost:4200`**.

6. **Detener y limpiar recursos al finalizar:**
   Presiona `Ctrl + C` en la terminal del túnel y ejecuta:
   ```bash
   kubectl delete -f k8s-frontend.yaml
   ```

---

## Ejecución en Modo Desarrollo Local (Sin Contenedor)

Si se requiere realizar cambios rápidos en la interfaz o estilos sin reconstruir la imagen Docker:

```bash
cd frontend-tienda
npm install
npm start
```

El servidor de desarrollo de Angular levantará en **`http://localhost:4200`**. *(Nota: en este modo no se cuenta con el reverse proxy de Nginx hacia los contenedores internos de Kubernetes).*

---

## Solución de Problemas Frecuentes (Troubleshooting)

* **`ERR_CONNECTION_REFUSED` en `localhost:4200`:**
  Verifica que el comando `kubectl port-forward service/frontend-tienda-service 4200:4200` siga activo en una terminal abierta y que el Pod esté en estado `1/1 Running` (`kubectl get pods`).

* **Banner *"No se pudo conectar con la API..."* dentro de la tienda:**
  Indica que la interfaz Angular y Nginx funcionan correctamente, pero el microservicio backend correspondiente (`8001`–`8008`) aún no está desplegado o encendido en el clúster.

* **Cambios en `nginx.conf` no reflejados tras `docker build`:**
  Reconstruye sin caché y actualiza la imagen del Deployment:
  ```bash
  docker build --no-cache -t frontend-tienda:v2 .
  kubectl rollout restart deployment/frontend-tienda
  ```
