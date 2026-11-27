
from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[1]

DEPLOYMENTS = ROOT / "deployments"
SERVICES = ROOT / "services"

DEPLOYMENTS.mkdir(exist_ok=True)
SERVICES.mkdir(exist_ok=True)

NAMESPACE = "tienda"
APP_CONFIG = "tienda-app-config"
DB_CONFIG = "tienda-database-config"

SERVICIOS = [
    {
        "nombre": "productos",
        "puerto": 8002,
        "db": "postgres",
        "prefijo_db": False,
        "interno": False,
        "urls": [],
        "cpu_request": "100m",
        "cpu_limit": "500m",
        "mem_request": "64Mi",
        "mem_limit": "256Mi",
    },
    {
        "nombre": "inventario",
        "puerto": 8004,
        "db": "postgres",
        "prefijo_db": False,
        "interno": True,
        "urls": ["PRODUCTOS_API_URL"],
        "cpu_request": "100m",
        "cpu_limit": "500m",
        "mem_request": "64Mi",
        "mem_limit": "256Mi",
    },
    {
        "nombre": "resenas",
        "puerto": 8008,
        "db": "mongodb",
        "interno": False,
        "urls": ["PRODUCTOS_API_URL"],
        "cpu_request": "100m",
        "cpu_limit": "500m",
        "mem_request": "128Mi",
        "mem_limit": "512Mi",
    },
    {
        "nombre": "envios",
        "puerto": 8003,
        "db": "postgres",
        "prefijo_db": True,
        "interno": True,
        "urls": ["PEDIDOS_API_URL"],
        "cpu_request": "100m",
        "cpu_limit": "500m",
        "mem_request": "128Mi",
        "mem_limit": "512Mi",
    },
    {
        "nombre": "carrito",
        "puerto": 8005,
        "db": "postgres",
        "prefijo_db": True,
        "interno": True,
        "urls": [
            "PRODUCTOS_API_URL",
            "INVENTARIO_API_URL",
        ],
        "cpu_request": "100m",
        "cpu_limit": "500m",
        "mem_request": "128Mi",
        "mem_limit": "512Mi",
    },
    {
        "nombre": "pagos",
        "puerto": 8007,
        "db": "postgres",
        "prefijo_db": True,
        "interno": True,
        "urls": ["PEDIDOS_API_URL"],
        "cpu_request": "250m",
        "cpu_limit": "1500m",
        "mem_request": "512Mi",
        "mem_limit": "1024Mi",
    },
    {
        "nombre": "frontend",
        "puerto": 4200,
        "db": None,
        "interno": False,
        "urls": [],
        "cpu_request": "50m",
        "cpu_limit": "250m",
        "mem_request": "32Mi",
        "mem_limit": "128Mi",
    },
]


def literal(nombre, valor):
    return (
        f"            - name: {nombre}\n"
        f"              value: {json.dumps(valor)}\n"
    )


def referencia(nombre, tipo, recurso, clave):
    return (
        f"            - name: {nombre}\n"
        f"              valueFrom:\n"
        f"                {tipo}KeyRef:\n"
        f"                  name: {recurso}\n"
        f"                  key: {clave}\n"
    )


def config(nombre, recurso, clave):
    return referencia(
        nombre, "configMap", recurso, clave
    )


def secret(nombre, recurso, clave):
    return referencia(
        nombre, "secret", recurso, clave
    )


def generar_env(s):
    nombre = s["nombre"]
    prefijo = nombre.upper()

    if nombre == "frontend":
        return literal(
            "NGINX_DNS_RESOLVER", "10.96.0.10"
        )

    env = ""

    env += config(
        "APP_ENV", APP_CONFIG, "APP_ENV"
    )

    for variable in [
        "JWT_ISSUER",
        "JWT_AUDIENCE",
        "JWT_PUBLIC_KEY_PATH",
    ]:
        env += config(
            variable, APP_CONFIG, variable
        )

    for variable in s["urls"]:
        env += config(
            variable, APP_CONFIG, variable
        )

    if s["interno"]:
        env += secret(
            "INTERNAL_API_TOKEN",
            "internal-api-secret",
            "INTERNAL_API_TOKEN",
        )

    if s["db"] == "postgres":
        env += config(
            "DB_HOST", DB_CONFIG, "DB_HOST"
        )

        env += config(
            "DB_PORT", DB_CONFIG, "DB_PORT"
        )

        db_prefix = (
            prefijo + "_" if s["prefijo_db"] else ""
        )

        env += config(
            db_prefix + "DB_NAME",
            DB_CONFIG,
            prefijo + "_DB_NAME",
        )

        env += config(
            db_prefix + "DB_USER",
            DB_CONFIG,
            prefijo + "_DB_USER",
        )

        env += secret(
            db_prefix + "DB_PASSWORD",
            nombre + "-db-secret",
            "DB_PASSWORD",
        )

    elif s["db"] == "mongodb":
        for variable in [
            "MONGO_HOST",
            "MONGO_PORT",
            "MONGO_DB",
            "MONGO_APP_USER",
        ]:
            env += config(
                variable, DB_CONFIG, variable
            )

        env += secret(
            "MONGO_APP_PASSWORD",
            "mongodb-secret",
            "MONGO_APP_PASSWORD",
        )

        env += secret(
            "MONGO_URI",
            "resenas-db-secret",
            "MONGO_URI",
        )

    if nombre == "pagos":
        env += literal(
            "JAVA_TOOL_OPTIONS",
            "-XX:MaxRAMPercentage=65.0",
        )

    return env


def generar_deployment(s):
    nombre = s["nombre"]
    frontend = nombre == "frontend"

    app = nombre if frontend else "api-" + nombre

    health = "/" if frontend else "/health"
    ready = "/" if frontend else "/ready"

    startup_threshold = (
        60 if nombre == "pagos" else 30
    )

    yaml = f"""apiVersion: apps/v1
kind: Deployment
metadata:
  name: {app}
  namespace: {NAMESPACE}
  labels:
    app: {app}

spec:
  replicas: 1
  revisionHistoryLimit: 3

  selector:
    matchLabels:
      app: {app}

  template:
    metadata:
      labels:
        app: {app}

    spec:
      automountServiceAccountToken: false

      containers:
        - name: {app}
          image: tienda/{app}:1.0.0
          imagePullPolicy: IfNotPresent

          ports:
            - name: http
              containerPort: {s["puerto"]}

          env:
"""

    yaml += generar_env(s)

    if not frontend:
        yaml += """
          volumeMounts:
            - name: jwt-public
              mountPath: /run/secrets
              readOnly: true
"""

    yaml += f"""
          resources:
            requests:
              cpu: "{s["cpu_request"]}"
              memory: "{s["mem_request"]}"
            limits:
              cpu: "{s["cpu_limit"]}"
              memory: "{s["mem_limit"]}"

          startupProbe:
            httpGet:
              path: {health}
              port: http
            periodSeconds: 5
            timeoutSeconds: 3
            failureThreshold: {startup_threshold}

          readinessProbe:
            httpGet:
              path: {ready}
              port: http
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 3

          livenessProbe:
            httpGet:
              path: {health}
              port: http
            periodSeconds: 15
            timeoutSeconds: 5
            failureThreshold: 3
"""

    if not frontend:
        yaml += """
      volumes:
        - name: jwt-public
          secret:
            secretName: jwt-public-secret
            defaultMode: 0444
"""

    return yaml


def generar_service(s):
    nombre = s["nombre"]

    app = (
        nombre if nombre == "frontend"
        else "api-" + nombre
    )

    return f"""apiVersion: v1
kind: Service
metadata:
  name: {app}
  namespace: {NAMESPACE}
  labels:
    app: {app}

spec:
  type: ClusterIP

  selector:
    app: {app}

  ports:
    - name: http
      port: {s["puerto"]}
      targetPort: http
      protocol: TCP
"""


def main():
    print("===== GENERANDO MANIFIESTOS =====")

    for servicio in SERVICIOS:
        nombre = servicio["nombre"]

        deployment = (
            DEPLOYMENTS / f"{nombre}-deployment.yaml"
        )

        service = (
            SERVICES / f"{nombre}-service.yaml"
        )

        deployment.write_text(
            generar_deployment(servicio),
            encoding="utf-8",
        )

        service.write_text(
            generar_service(servicio),
            encoding="utf-8",
        )

        print(f"[OK] {deployment.relative_to(ROOT)}")
        print(f"[OK] {service.relative_to(ROOT)}")

    print("\nDeployments generados: 7")
    print("Services generados: 7")
    print("Total de manifiestos: 14")


if __name__ == "__main__":
    main()
