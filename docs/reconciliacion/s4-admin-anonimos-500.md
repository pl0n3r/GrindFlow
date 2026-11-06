# GrindFlow #917 — 500 anónimos en API admin S4: conciliación de evidencia

> **Corte: 2026-11-06 · recuperación HTTP observada, causa específica del 500 NO DEMOSTRADA.** Este expediente es solo documental. No declara un nuevo deploy, restore exitoso, ni validación productiva exact-SHA de Symfony. El issue #903 tiene alcance distinto (404 de rutas).

## Autoridad, identidad y fuentes

- Fuente contractual: [issue #917](https://github.com/pl0n3r/GrindFlow/issues/917); [handoff de auditoría #918](https://github.com/pl0n3r/GrindFlow/issues/917#issuecomment-6631139904).
- Autorización OWNER de 2026-11-06: [comentario #6686368279](https://github.com/pl0n3r/GrindFlow/issues/917#issuecomment-6686368279), **solo lectura y sin datos privados**, limitada al diagnóstico de estas tres rutas; no habilita escritura, reinicio, deploy ni cambios Hostinger.
- Evidencia histórica de los 500/401: [comentario #6651765795](https://github.com/pl0n3r/GrindFlow/issues/917#issuecomment-6651765795) (sondeo del dueño, 2026-11-05). Evidencia del runtime y cache: [#903 / comentario #6651765531](https://github.com/pl0n3r/GrindFlow/issues/903#issuecomment-6651765531).
- Regresión de aplicación: [#905](https://github.com/pl0n3r/GrindFlow/issues/905) y [PR #916 fusionado](https://github.com/pl0n3r/GrindFlow/pull/916), squash `8f5b200289e2b53a94530b0d35b12eb83fb21f51`; prueba real de kernel `symfony/tests/php/AdminUnauthenticatedResponseTest.php`.
- Sondeo independiente de esta sesión: HTTPS **GET anónimo**, sin cookies ni credenciales, desde dispositivo conectado autorizado, **2026-11-06 ~15:00 UTC**. El cliente descartó los cuerpos en la verificación de estado/MIME y extrajo **solo** `error.code` de los JSON públicos. Sin leer ni publicar información privada.

| GET en `https://www.grindflow.com.co` | 4-nov, antes (reporte #917) | 5-nov, después (reporte OWNER) | 6-nov, verificación independiente |
| --- | --- | --- | --- |
| `/s4/api/admin/context` | **500** `text/html` | **401** `application/json` | **401** `application/json`; `authentication_required` |
| `/s4/api/admin/vault` | **500** `text/html` | **401** `application/json` | **401** `application/json`; `authentication_required` |
| `/s4/api/admin/rules/weekly/preview` | **500** `text/html` | **401** `application/json` | **401** `application/json`; `authentication_required` |

Identidad en el mismo sondeo:

- `GET /health`: HTTP **200**, `status=ok`, `version=0.1.227`, `commit=d1bd945695e93beaefd1a70bac433aa42a4d1d14`, `exact=true`; coincide con `GrindFlow/main` leído al iniciar este ciclo. Es identidad de **Laravel/raíz**, **no** una atestación exacta de Symfony.
- `GET /s4/health`: HTTP **200**, JSON, `status=ok`, `version=0.1.227`, `stage=s0-preview`, **`release_sha=null`**. El runtime Symfony **no** acredita el mismo SHA. Una coincidencia de versión legible no prueba identidad exacta de S4.
- No se realizó ningún cambio de código, instalación, comando de mantenimiento, actualización de caché, SSH, migración, manipulación de permisos ni escritura en el host durante este sondeo.

### Reproducción segura de la observación HTTP

Usar exclusivamente endpoints públicos, con `GET` sin sesión; no almacenar cuerpos ni cabeceras sensibles. Por ejemplo:

```sh
for path in /s4/api/admin/context /s4/api/admin/vault /s4/api/admin/rules/weekly/preview; do
  printf '%s ' "$path"
  curl --silent --show-error --location --output /dev/null \
    --write-out 'HTTP %{http_code}; MIME %{content_type}\n' --max-time 15 \
    "https://www.grindflow.com.co$path"
done
```

La comparación del campo público `error.code` se hizo mediante parseo JSON local, imprimiendo únicamente el valor `authentication_required`, no el contenido integral. Para atestación de identidad: comparar la salida allowlisted de `/health` y `/s4/health` con la fuente exacta en GitHub; si `release_sha=null`, el resultado exact-SHA de Symfony sigue siendo **UNKNOWN**.

## Análisis diferencial: #917 (500) frente a #903 (404)

1. **#903 sí tiene evidencia de caché obsoleta.** El OWNER observó `symfony/var/cache/prod` y `var/cache/production` fechados el **2026-10-06**, anteriores a rutas introducidas el 11-oct. Declaró backup `var/cache/prod.bak-20261105` y ejecución de `APP_ENV=prod php bin/console cache:clear --env=prod --no-debug` el **2026-11-05**. Cinco rutas nuevas dejaron el 404 HTML tras esa intervención; Reports y DSR tienen circunstancias independientes. Esta es evidencia comunicada por el dueño en [#903/#6651765531](https://github.com/pl0n3r/GrindFlow/issues/903#issuecomment-6651765531), **no una operación ejecutada o auditada de forma independiente en este PR**.
2. **#917 se refiere a tres rutas previamente registradas que devolvían 500 HTML**, no al 404 de controladores recientes. Sus tres respuestas se convirtieron en 401 JSON tras la misma intervención temporal y continuaban correctas en el sondeo nuevo. Esto sostiene que el problema **estuvo correlacionado** con el estado del runtime y pudo compartir causa con la caché compilada antigua, **pero no permite identificar la excepción concreta de los tres 500**.
3. **Hipótesis remanentes, no hechos probados:** caché/container Symfony anticuado, carga de servicios/definiciones de controlador, estado de PHP/OPCache u otra excepción previa al guard de autenticación. No se inspeccionaron logs sanitizados de excepciones del host. Evitar describir esa incertidumbre como causa raíz confirmada de #917.
4. En código, la regresión del PR #916 ejercita kernel real y cuatro solicitudes anónimas (tres rutas y Vault con query inválida), exige `401`, `application/json`, `authentication_required`, `Cache-Control: private, no-store` y ausencia de HTML. Esta prueba distingue comportamiento **en código** de la observación del hosting. La prueba **negativa autenticada** de aislamiento tenant/rol en ensayo, separada de #916, se acredita con la ejecución de `symfony-preview` indicada en la sección QA siguiente; no sustituye la observación del hosting.

## Diagnóstico S4 remoto owner-only, solo lectura — 2026-11-06

Se ejecutó [S4 Router Diagnostics #42241179721](https://github.com/pl0n3r/GrindFlow/actions/runs/42241179721) con `workflow_dispatch` bajo el actor y triggering actor `pl0n3r`, exactamente en `main@d1bd945695e93beaefd1a70bac433aa42a4d1d14`. La ejecución terminó **`completed/success`**; el job `owner-only-s4-router-readonly` y todos sus pasos finalizaron en `success`: comprobación exact-main antes/después, validación sintáctica del helper, y lectura SSH categórica del checkout Hostinger sin escrituras.

El helper `scripts/s4-router-diagnostics.sh --read-only` impide un resultado `success` cuando el `git rev-parse HEAD` remoto no coincide con el SHA esperado; el workflow valida además 18 campos `S4_DIAG_*` en formato cerrado. Por tanto, el **checkout inspeccionado** coincidía con `main@d1bd945…` durante esa ejecución. El informe por categorías `MATCH/DRIFT/MISSING/UNKNOWN` de los demás campos se publica en el **step summary de GitHub Actions**, no en el objeto público del check-run ni en los logs descargables consultados aquí; por eso **no** se declara el estado individual de `RELEASE_MARKER` ni `CACHE` desde un `success` global.

**Frontera de evidencia:** aunque el checkout remoto inspeccionado coincide con `main`, la API pública `/s4/health` continúa devolviendo `release_sha=null`; no se puede afirmar que la petición HTTP provenga inequívocamente del artefacto/checkout inspeccionado ni que se haya identificado la excepción original del 500. Esta ejecución fue una **lectura autorizada**, no un deploy, un `cache:clear`, una prueba de rollback ni un tratamiento de datos.

## QA tenant/rol acreditada en ensayo aislado — 2026-11-06

El [GrindFlow CI #42200060990](https://github.com/pl0n3r/GrindFlow/actions/runs/42200060990) fue `workflow_dispatch` en `d1bd945695e93beaefd1a70bac433aa42a4d1d14`, `completed/success`; el job [`symfony-preview` #127833165473](https://github.com/pl0n3r/GrindFlow/actions/runs/42200060990/job/127833165473) terminó `success`. Su registro acredita `php vendor/bin/simple-phpunit -c phpunit.xml.dist` sobre MariaDB 11.4 descartable: **388 tests, 7857 assertions, 1 risky** (`PlanLimitsTest`; no se oculta ese indicador). El mismo job ejecutó expresamente `VaultTest` con **1 test, 147 assertions, OK**. El archivo `symfony/phpunit.xml.dist` incluye todo `tests/php` en la suite `S0 Symfony`, sin excluir las clases siguientes.

- [`TenantIsolationNegativeTest.php`](../../symfony/tests/php/TenantIsolationNegativeTest.php): dos tenants y activos ficticios en DB de CI, denegación `404 file_not_found` al acceder a activos ajenos; rechazos `403` por rol, CSRF y purge.
- [`CrossTenantIdorSweepTest.php`](../../symfony/tests/php/CrossTenantIdorSweepTest.php): comparación de respuestas para ID ajeno/inexistente y protección contra filtración de existencia entre tenants sintéticos (`403/404`).
- [`VaultTest.php`](../../symfony/tests/php/VaultTest.php) y [`WeeklyRulePreviewTest.php`](../../symfony/tests/php/WeeklyRulePreviewTest.php): guardas `401` sin sesión, restricciones de escritura y revocación de acceso `403`/conflicto `409` usando fixtures ficticios.

La comparación GitHub autenticada entre `d1bd945…` y `main@4f62db7863771f32d4c6b16df5df7c3afa41d151` contiene **únicamente `docs/matriz-cobertura.md`**; por tanto, el código Symfony y estas pruebas son idénticos entre el run QA y el `main` base del PR #983. El PR cambia únicamente este informe, no las suites ni el runtime.

**Alcance:** queda respaldada la parte de QA negativa de tenant/rol **en ensayo aislado**; no prueba identidad del checkout Symfony que atiende HTTP en producción, excepción histórica del 500 ni integridad/ensayo real del backup/rollback. Esos AC siguen parciales.

## Criterios de aceptación #917: evaluación estricta

| Criterio | Evidencia | Estado |
| --- | --- | --- |
| **AC-01** HTTP sin sesión + SHA/versión | Tres 401 JSON autenticados por sondeo público, 2026-11-06. `/health` identifica main; `/s4/health.release_sha=null` impide correlación Symfony exacta. | **PARCIAL** (HTTP sí; identidad S4 no) |
| **AC-02** causa del 500 respaldada por logs/diagnóstico | #903 demuestra la caché antigua y temporalidad de recuperación, pero no la excepción específica de los 500 de #917. La distinción 500 ≠ 404 queda acreditada. | **PARCIAL / causa específica UNKNOWN** |
| **AC-03** 3×401 + respaldo/rollback del repair | Las tres respuestas **cumplen**. El OWNER declara un backup de caché antes del `cache:clear`, pero faltan recibos verificables de integridad/restore/rollback del runtime S4 y alcance de autorización de esa escritura. La prueba tenant/rol en ensayo aislado **sí** está acreditada arriba mediante CI real, sin suplantar la validación operativa. | **PARCIAL** |
| **AC-04** `Factory CI reusable / Validar` del HEAD final | La regresión #916 ya fue fusionada; este archivo requiere CI **del SHA de su propio PR**, no heredar los checks de #916. | **PENDIENTE de HEAD final** |

## Desbloqueo operativo y riesgos

- **Solo lectura permitida** por el OWNER: recoger desde canal autenticado autorizado, con sanitización previa y sin reproducir secretos o datos personales, error/stack mínimo de las tres peticiones históricas, timestamps, servicio/route matching, fecha/hash de caché y `git rev-parse HEAD` del checkout S4; si el log histórico ya expiró, registrar `NO_DISPONIBLE`, no inventar causa. No inspeccionar cuentas/tenants ni sesiones reales.
- Para cualquier nueva escritura Hostinger, **exigir otra autorización específica**, backup realmente verificable, evidencia de restore/rollback, alcance exact-SHA y prueba posterior HTTP/health conforme a D-043/D-059. La autorización para lectura de 6-nov **no** basta.
- QA tenant/rol en ensayo con fixtures ficticios: evidencia enlazada arriba, `symfony-preview` terminal `success` en el mismo código Symfony. Una nueva modificación de tests/runtime requiere repetir QA exacto; no extender el claim documental de #917 a código/test sin renovar legítimamente el contrato V3.
- Tras superar estas pruebas, comprobar el HEAD exacto del PR, `Factory CI reusable / Validar`, SonarCloud, CodeQL, revisión CodeRabbit **FINAL única** (solo con CI verde y PR definitivo) y protección de rama. Cualquier despliegue/producto queda fuera de este PR.

**Dictamen:** HTTP anónimo **recuperado y revalidado**; causa puntual del 500 e identidad exacta de Symfony **no acreditadas**. Este expediente de preparación **no equivale** a cierre total del incidente, certificación de go-live, backup restaurado o autorización de merge/activación por inferencia.
