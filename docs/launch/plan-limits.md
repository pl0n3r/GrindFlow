# Límites por plan · propuesta sin aprobación comercial

**Estado:** PROPUESTA / NO ENSAYADO. **Decisión de salida:** NO-GO.
**Autoridad comercial:** DEL_DUEÑO. **Disponibilidad comercial:** UNKNOWN.
**Validación de planes y aprobación del operador:** UNKNOWN. Ninguna cifra de esta tabla autoriza cobros, cambios de plan, conexiones reales, despliegue o go-live.

## Fuentes y alcance

- [Lista de salida a producción](../lista-salida-produccion.md): fila **«Límites por plan: storage, ancho de banda, colas y llamadas API»**, aún **DEL_DUEÑO**. El OWNER y Producto deben decidir cotas tras medir el piloto; este documento no cambia esa fila.
- [Modelo de costos del piloto](cost-model.md): cantidades, tarifas, consumo y presupuesto **UNKNOWN**, sin autorización de gasto.
- [Roadmap comercial #353](https://github.com/pl0n3r/GrindFlow/issues/353): precios y límites requieren validación comercial; sin decisión aplicada.
- [Reglas técnicas de uploads](../AGENT-VAULT-UPLOADS.md): Quick Upload tiene tope técnico **8 MB** y direct upload **2 GiB**. Son restricciones del transporte, **NO son cuotas comerciales ni valores de la matriz**. Su disponibilidad real requiere pruebas y permisos separados.

## Escenarios comparables: todas las cuotas comerciales pendientes

A, B y C son **escenarios de análisis**, no nombres de planes vendibles. Cada fuente numérica comercial es **UNKNOWN**. Las unidades sirven únicamente para diseñar la futura instrumentación: **PROPUESTA / NO ENSAYADO**. No se incluye ni deduce un precio.

| Escenario | Recurso | Unidad de cuota comercial | Límite comercial | Fuente de la cuota | Métrica propuesta de control | Estado de medición |
| --- | --- | --- | --- | --- | --- | --- |
| A | storage | GiB por tenant | UNKNOWN | UNKNOWN | bytes retenidos por organización, versiones y backups | PROPUESTA / NO ENSAYADO |
| A | tamaño de archivo | MB por archivo | UNKNOWN | UNKNOWN | tamaño validado de cada asset del tenant | PROPUESTA / NO ENSAYADO |
| A | publicaciones | publicaciones por mes | UNKNOWN | UNKNOWN | entregas consumadas por tenant y período | PROPUESTA / NO ENSAYADO |
| A | conectores | conectores por tenant | UNKNOWN | UNKNOWN | conexiones autorizadas activas por tenant | PROPUESTA / NO ENSAYADO |
| A | colas | trabajos concurrentes por tenant | UNKNOWN | UNKNOWN | jobs pendientes y ejecutando por tenant | PROPUESTA / NO ENSAYADO |
| A | ancho de banda | GiB egresados por mes | UNKNOWN | UNKNOWN | bytes de transferencia atribuibles a cada tenant | PROPUESTA / NO ENSAYADO |
| A | llamadas API | peticiones por período | UNKNOWN | UNKNOWN | llamadas aceptadas, rechazadas y limitadas por tenant | PROPUESTA / NO ENSAYADO |
| B | storage | GiB por tenant | UNKNOWN | UNKNOWN | bytes retenidos por organización, versiones y backups | PROPUESTA / NO ENSAYADO |
| B | tamaño de archivo | MB por archivo | UNKNOWN | UNKNOWN | tamaño validado de cada asset del tenant | PROPUESTA / NO ENSAYADO |
| B | publicaciones | publicaciones por mes | UNKNOWN | UNKNOWN | entregas consumadas por tenant y período | PROPUESTA / NO ENSAYADO |
| B | conectores | conectores por tenant | UNKNOWN | UNKNOWN | conexiones autorizadas activas por tenant | PROPUESTA / NO ENSAYADO |
| B | colas | trabajos concurrentes por tenant | UNKNOWN | UNKNOWN | jobs pendientes y ejecutando por tenant | PROPUESTA / NO ENSAYADO |
| B | ancho de banda | GiB egresados por mes | UNKNOWN | UNKNOWN | bytes de transferencia atribuibles a cada tenant | PROPUESTA / NO ENSAYADO |
| B | llamadas API | peticiones por período | UNKNOWN | UNKNOWN | llamadas aceptadas, rechazadas y limitadas por tenant | PROPUESTA / NO ENSAYADO |
| C | storage | GiB por tenant | UNKNOWN | UNKNOWN | bytes retenidos por organización, versiones y backups | PROPUESTA / NO ENSAYADO |
| C | tamaño de archivo | MB por archivo | UNKNOWN | UNKNOWN | tamaño validado de cada asset del tenant | PROPUESTA / NO ENSAYADO |
| C | publicaciones | publicaciones por mes | UNKNOWN | UNKNOWN | entregas consumadas por tenant y período | PROPUESTA / NO ENSAYADO |
| C | conectores | conectores por tenant | UNKNOWN | UNKNOWN | conexiones autorizadas activas por tenant | PROPUESTA / NO ENSAYADO |
| C | colas | trabajos concurrentes por tenant | UNKNOWN | UNKNOWN | jobs pendientes y ejecutando por tenant | PROPUESTA / NO ENSAYADO |
| C | ancho de banda | GiB egresados por mes | UNKNOWN | UNKNOWN | bytes de transferencia atribuibles a cada tenant | PROPUESTA / NO ENSAYADO |
| C | llamadas API | peticiones por período | UNKNOWN | UNKNOWN | llamadas aceptadas, rechazadas y limitadas por tenant | PROPUESTA / NO ENSAYADO |

## Condiciones para sustituir UNKNOWN

1. **OWNER/Producto:** documentar la decisión comercial explícita y la fuente primaria de la cuota. No inferir cantidades a partir de los topes técnicos del Vault.
2. **Ingeniería/QA:** identificar la implementación concreta y una prueba reproducible por tenant que mida cada recurso. No asumir un contador de consumo por la existencia de una tabla, comando o ruta.
3. **Operación:** obtener aprobación de costo, privacidad y tratamiento de datos antes de inspeccionar proveedores o datos reales; validar versión/SHA, entorno, límites, backup y rollback.
4. **Go-live:** decisión separada del dueño. Este documento nunca cambia el estado **DEL_DUEÑO** de la lista de salida, ni convierte un fixture o CI verde en disponibilidad productiva.

## Reversión

Revertir únicamente `docs/launch/plan-limits.md` y `tests/test_limites_plan_doc.py`. No se generan migraciones, secretos, cambios de infraestructura ni reglas comerciales activas.
