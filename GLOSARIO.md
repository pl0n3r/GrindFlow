# GrindFlow — Glosario para producto y operaciones

Este glosario explica términos presentes en el [README](README.md), el [roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2) y los módulos de GrindFlow. Es una herramienta de lectura, no sustituye a los contratos técnicos ni a las pruebas.

| Término | Qué significa para el negocio |
| --- | --- |
| **Tenant / organización** | Equipo cuyos datos permanecen separados de los demás equipos de GrindFlow. |
| **Vault** | Biblioteca del equipo donde se registran archivos y sus versiones de procesamiento. |
| **Asset elegible** | Archivo cuyo procesamiento actual terminó correctamente y que puede programarse. |
| **Scheduler** | Agenda publicaciones en una fecha y zona horaria explícita para destinos activos. |
| **Distribution** | Gestiona intentos de entrega y resultados, incluidos errores y reintentos limitados. |
| **Traffic** | Agrupa clics por enlace corto, campaña y fecha, sin exportar datos de visitantes individuales. |
| **Tracked link** | Enlace corto que redirige y permite contar clics. Cambiar su destino no rota el token. |
| **Finance ledger** | Registro inmutable de asignaciones y reversas; no equivale a una cuenta bancaria. |
| **Reversa** | Nuevo registro que neutraliza una asignación anterior, sin borrar la original. |
| **Conciliación** | Comparación de asignaciones y reversas de un periodo y moneda; no significa que haya existido un pago externo. |
| **CI** | Pruebas automáticas de código en GitHub; un CI verde no demuestra que producción esté actualizada. |
| **Gate / compuerta** | Control obligatorio que debe pasar antes de integrar el cambio a `main`. |
| **Sonar / CodeRabbit** | Revisiones auxiliares de calidad y código, distintas de las pruebas automáticas. |
| **PR / Pull Request** | Propuesta visible de cambios con código, contexto y evidencia de validación. |
| **SHA / commit** | Huella precisa de una versión del código, diferente del número comercial de release. |
| **Versión objetivo** | Versión para la cual se prepara una entrega: no acredita despliegue. |
| **Versión desplegada** | Versión humana observada en el entorno; por sí sola no demuestra el SHA remoto. |
| **Production Smoke** | Lecturas seguras que comprueban salud y rutas reales sin escribir datos ni contar clics. |
| **E2E** | Prueba del recorrido completo con navegador y datos descartables de CI. |
| **Migración** | Cambio explícito de la estructura de base de datos; un merge no ejecuta migraciones. |
| **Backup restaurable** | Copia cuya restauración pudo comprobarse en un entorno seguro, no solo un archivo creado. |
| **CodeRabbit review final** | Revisión publicada sobre el head estable; solicitarla no equivale a haberla recibido. |

Si aparece nueva terminología relevante para alguien no técnico, actualizar este glosario en la misma PR.
