# GF347 · Estados de conectores (contrato offline)

Relacionado: [#347](https://github.com/pl0n3r/GrindFlow/issues/347) y [#677](https://github.com/pl0n3r/GrindFlow/issues/677).

## Alcance y autoridad

`GrindFlow\Infrastructure\Connectors\ConnectorState` es una máquina de estados **aislada** para validar la política de circuitos de un conector sin red, sin base de datos, sin un proveedor externo y sin tratar datos reales. No conecta una cuenta, no valida credenciales reales, no abre acceso a servicios ni publica contenido. Los callbacks de `retry()` son **transportes sintéticos** y no deben sustituirse por conexiones reales hasta contar con autorización separada del propietario, controles de tenant, idempotencia y contrato de proveedor probado.

El llamador que integre el contrato posteriormente debe autorizar la identidad/tenant, verificar un reloj monotónico confiable y clasificar una respuesta remota antes de invocar `observe()`; jamás pasar errores, tokens, payloads HTTP ni PII de proveedor. `reconnect(true, ...)` **no** autoriza ninguna conexión por sí solo: presupone una autorización verificada externamente y obliga a una prueba sintética posterior antes de pasar a activo. `revoke()` es terminal; ninguna ruta de la clase reactiva un token revocado.

## Transiciones explícitas

| Estado actual | Señal o acción | Resultado | Regla |
| --- | --- | --- | --- |
| `active` | `observe(['status'=>'ok'])` | `active` | Sin transporte ni cambio de autoridad |
| `active` | cuota con `retry_after` entero entre 60 y 3600 s | `quota_exhausted` | Espera obligatoria |
| `active` | credencial inválida | `invalid_credential` | Requiere autorización externa para reconectar |
| `active` | credencial revocada | `revoked` | Terminal |
| `active` | fallo transitorio | `retrying` | Primer backoff 60 s |
| `quota_exhausted` / `retrying` | `retry(fake, now)` antes de `retry_at` | Sin cambio | **Cero invocaciones del fake** |
| `quota_exhausted` / `retrying` | `retry(fake, now)` permitido | Según la respuesta sintética | Máximo tres invocaciones en la secuencia |
| `invalid_credential` | `reconnect(true, now)` autorizado | `retrying` | Necesita posterior `retry(fake, now)` con `ok` |
| `retrying` agotado | `reconnect(true, now)` autorizado | `retrying` | Reinicia presupuesto, no supone conexión válida |
| Cualquier estado | `revoke(now)` | `revoked` | Terminal e idempotente |
| `revoked` | `observe`, `retry`, `reconnect` | Rechazado / sin llamadas | Nunca pasa a activo |

Los errores de transporte, respuestas desconocidas o campos adicionales se tratan como fallo transitorio **sin reflejar el contenido**. Un `observe(['status'=>'ok'])` no permite saltar una espera: solo se admite desde `active`. La ventana de cuota se decide con `retry()` explícito. En reintentos transitorios el backoff es de 60, 300 y 900 segundos; el máximo es tres invocaciones. Tras agotarlas, `retry_at` es `null`, `can_retry=false` y se requiere intervención autorizada. Los tiempos son valores sintéticos del llamador, **no** una fecha de proveedor ni una garantía de servicio.

`summary()` expone exclusivamente `state`, `retry_at`, `attempts`, `can_retry` y `message` fijo. No contiene credenciales, datos personales, URLs de callback, contenido de excepción ni errores de proveedor. Los estados y restricciones se validan con entradas de esquema cerrado y reloj no regresivo.

## Verificación y reversión

- **AC-01:** `GrindFlow CI / validate` en SUCCESS para el HEAD exacto del PR.
- **AC-02:** `symfony-preview` en SUCCESS; PHPUnit ejecuta `symfony/tests/php/ConnectorStateTransitionsTest.php` con transporte falso, negativos de cuota, credenciales, revocación, intentos agotados, reloj regresivo, datos inesperados y excepciones sanitizadas.
- Una mutación que elimine la guarda de espera debe hacer fallar `testQuotaCannotRetryEarlyAndDoesNotInvokeFakeTransport`; eliminar el bloqueo contra `observe(ok)` debe hacer fallar `testCannotBypassQuotaWithInjectedOkObservation`.
- La integración real de proveedores, gastos, secretos, credenciales, datos personales, deploy y go-live **está fuera de alcance**. Ni una revisión automática ni CI verde acreditan un entorno de producción.
- Reversión: revertir el squash que añade los tres archivos; no existe migración, escritura externa ni efecto productivo.
