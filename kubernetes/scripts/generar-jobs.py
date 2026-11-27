from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
JOBS = ROOT / "jobs"

JOBS.mkdir(exist_ok=True)

SERVICIOS = [
    "productos",
    "inventario",
    "envios",
    "carrito",
    "pagos",
]


def generar_job(servicio):
    prefijo = servicio.upper()

    return f"""apiVersion: batch/v1
kind: Job
metadata:
  name: {servicio}-migrate
  namespace: tienda
  labels:
    app: {servicio}-migrate
spec:
  backoffLimit: 3
  activeDeadlineSeconds: 300
  template:
    metadata:
      labels:
        app: {servicio}-migrate
    spec:
      restartPolicy: Never
      automountServiceAccountToken: false
      containers:
        - name: migrate
          image: postgres:16
          imagePullPolicy: IfNotPresent
          command:
            - psql
          args:
            - "-X"
            - "-v"
            - "ON_ERROR_STOP=1"
            - "--single-transaction"
            - "-f"
            - "/migrations/init.sql"
          env:
            - name: PGHOST
              value: postgres
            - name: PGPORT
              value: "5432"
            - name: PGDATABASE
              valueFrom:
                configMapKeyRef:
                  name: tienda-database-config
                  key: {prefijo}_DB_NAME
            - name: PGUSER
              valueFrom:
                configMapKeyRef:
                  name: tienda-database-config
                  key: {prefijo}_DB_USER
            - name: PGPASSWORD
              valueFrom:
                secretKeyRef:
                  name: {servicio}-db-secret
                  key: DB_PASSWORD
          volumeMounts:
            - name: migrations
              mountPath: /migrations
              readOnly: true
          resources:
            requests:
              cpu: "50m"
              memory: "32Mi"
            limits:
              cpu: "250m"
              memory: "128Mi"
      volumes:
        - name: migrations
          configMap:
            name: {servicio}-migration-sql
"""


def main():
    print("===== GENERANDO JOBS DE MIGRACION =====")

    for servicio in SERVICIOS:
        archivo = JOBS / f"{servicio}-migrate.yaml"

        archivo.write_text(
            generar_job(servicio),
            encoding="utf-8"
        )

        print(f"[OK] {archivo.relative_to(ROOT)}")

    print("\nJobs generados: 5")


if __name__ == "__main__":
    main()
