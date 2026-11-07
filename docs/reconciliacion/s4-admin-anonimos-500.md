# GrindFlow #917 — 500 anónimos en API admin S4: conciliación de evidencia


## Corte fresco S4 — 2026-11-07T23:19:54Z; reconciliación con main
<!-- GF917-HTTPS-231954Z-MAIN1A6D -->

**Observación nueva, ejecutada y limitada por autorización OWNER [#6686368279](https://github.com/pl0n3r/GrindFlow/issues/917#issuecomment-6686368279):** seis solicitudes HTTPS `GET` públicas desde el dispositivo conectado autorizado, TLS verificado, sin sesión, cookies, autenticación, SSH, POST ni escrituras. Se leyeron como máximo 10 KiB en memoria por respuesta; **solo** se expusieron estado, MIME y campos JSON allowlisted, sin cuerpos ni cabeceras privadas. Proceso `exit=0`, inicio `2026-11-07T23:19:54.388440Z`.

| Endpoint | Resultado |
| --- | --- |
| `/s4/api/admin/context` | **401** `application/json`; `error.code=authentication_required` |
| `/s4/api/admin/vault` | **401** `application/json`; `error.code=authentication_required` |
| `/s4/api/admin/rules/weekly/preview` | **401** `application/json`; `error.code=authentication_required` |
| `/health` (Laravel raíz) | **200 JSON**, `status=ok`, `version=0.1.227`, `commit=1a6d10fb82dd82de1afbda3ce3fc611c1553af8f`, `exact=true` |
| `/s4/health` | **200 JSON**, `stage=s0-preview`, `version=0.1.227`, **`release_sha=null`** |
| `/s4/_bridge-readiness` | **200 JSON**, sin `sha/commit/version/release_sha` en campos públicos allowlisted |

En el mismo `main@1a6d10fb82dd82de1afbda3ce3fc611c1553af8f`, [S4 Runtime Prepare #42422504846](https://github.com/pl0n3r/GrindFlow/actions/runs/42422504846) terminó `completed/success`, con `S4_RUNTIME_PREPARE_OK` en log y verificación pública del bridge. **Este workflow no acredita qué proceso exacto sirve HTTP Symfony, la excepción histórica de los tres 500, ni integridad/restore/rollback del respaldo declarado.** El commit nuevo de `main` modifica archivos de distribución Symfony, no esta conciliación.

**AC-01 PARCIAL:** tres HTTP correctos, identidad exact-SHA de S4 **UNKNOWN** por `release_sha=null` (el SHA anterior solo describe Laravel raíz). **AC-02 PARCIAL:** no hay stack/exception sanitizada de los 500 ni prueba primaria de su imposibilidad de recuperación, distinta de #903/404. **AC-03 PARCIAL:** 3×401 sí, backup `var/cache/prod.bak-20261105` sin recibo de integridad/restaurabilidad/rollback. **AC-04:** CI debe ejecutarse y verificarse en el HEAD de este documento, sin atribuirle credenciales de producción.

**Acción no realizada:** acceso SSH, lectura de registros privados, modificación Hostinger, backup/restore, cache:clear, restart, deploy, cambio de roles/credenciales, CodeRabbit intermedia o merge. La autorización OWNER actual es exclusivamente de lectura sin datos privados; para operaciones de host hace falta permiso adicional específico y evidencia de respaldo/reversión. El expediente no puede pasar de `DRAFT` ni fusionarse como si el runtime fuese validado.


> **Corte: 2026-11-07 · recuperación HTTP revalidada, causa específica del 500 NO DEMOSTRADA.** Este expediente es solo documental. No declara un nuevo deploy, restore exitoso, ni validación productiva exact-SHA de Symfony. El issue #903 tiene alcance distinto (404 de rutas).

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
| **AC-04** `Factory CI reusable / Validar` del HEAD final | `validate`, `Factory CI reusable / Validar`, SonarCloud, CodeQL, aceptación y coordinación **success** en PR #983 HEAD `b0898f6d84b201245bac5425d2ff81588fb3162f` tras reentrada con `Closes #917`. Cualquier commit posterior requiere checks **de su SHA propio**. | **ACREDITADO para b0898f6d; pendiente revalidar HEAD final si cambia** |

## Desbloqueo operativo y riesgos

- **Solo lectura permitida** por el OWNER: recoger desde canal autenticado autorizado, con sanitización previa y sin reproducir secretos o datos personales, error/stack mínimo de las tres peticiones históricas, timestamps, servicio/route matching, fecha/hash de caché y `git rev-parse HEAD` del checkout S4; si el log histórico ya expiró, registrar `NO_DISPONIBLE`, no inventar causa. No inspeccionar cuentas/tenants ni sesiones reales.
- Para cualquier nueva escritura Hostinger, **exigir otra autorización específica**, backup realmente verificable, evidencia de restore/rollback, alcance exact-SHA y prueba posterior HTTP/health conforme a D-043/D-059. La autorización para lectura de 6-nov **no** basta.
- QA tenant/rol en ensayo con fixtures ficticios: evidencia enlazada arriba, `symfony-preview` terminal `success` en el mismo código Symfony. Una nueva modificación de tests/runtime requiere repetir QA exacto; no extender el claim documental de #917 a código/test sin renovar legítimamente el contrato V3.
- Tras superar estas pruebas, comprobar el HEAD exacto del PR, `Factory CI reusable / Validar`, SonarCloud, CodeQL, revisión CodeRabbit **FINAL única** (solo con CI verde y PR definitivo) y protección de rama. Cualquier despliegue/producto queda fuera de este PR.

**Dictamen:** HTTP anónimo **recuperado y revalidado**; causa puntual del 500 e identidad exacta de Symfony **no acreditadas**. Este expediente de preparación **no equivale** a cierre total del incidente, certificación de go-live, backup restaurado o autorización de merge/activación por inferencia.


## Reconciliación independiente adicional — 2026-11-07 01:52 UTC

- Se ejecutó desde equipo autorizado un sondeo de **cinco GET HTTPS anónimos, sin cookies, sesión ni datos privados**. Las tres rutas `/s4/api/admin/context`, `/s4/api/admin/vault` y `/s4/api/admin/rules/weekly/preview` devolvieron cada una **401 `application/json` con `error.code=authentication_required`**. No se reprodujo el 500 histórico. `/health` devolvió **200 JSON**, `version=0.1.227`, `commit=f0da143fa242f202e03becea4c4aa5690e1ebbd9`, `exact=true`: identidad del **runtime raíz Laravel** alineada con GitHub `main`. `/s4/health` devolvió **200 JSON**, `stage=s0-preview`, **`release_sha=null`**: la identidad exacta de Symfony que sirve el HTTP sigue **NO ACREDITADA**.
- En la [ejecución S4 Router Diagnostics #42327484908](https://github.com/pl0n3r/GrindFlow/actions/runs/42327484908), disparada una sola vez por el propietario mediante `workflow_dispatch` sobre **`main@f0da143fa242f202e03becea4c4aa5690e1ebbd9`**, el job `owner-only-s4-router-readonly` terminó **`success`**. Sus pasos de validación exact-main anterior/posterior, lectura SSH Hostinger solo lectura y helper de 18 categorías fueron `success`. El helper **rechaza** un checkout remoto diferente de `EXPECTED_SHA`, por lo que queda acreditada la **identidad del checkout inspeccionado** en ese instante. **No** queda acreditado que el tráfico HTTP Symfony de `/s4` sirva inequívocamente ese checkout; `release_sha=null` persiste.
- La salida individual `S4_DIAG_RELEASE_MARKER`, `S4_DIAG_CACHE` y otras categorías del **GitHub Actions step summary** no consta en el objeto del check ni en logs descargables de la sesión: **no inferir MATCH** para cada categoría solo porque el job sea `success`. El workflow no realiza escrituras productivas, SSH interactivo, Composer, `bin/console`, `cache:clear`, deploy, backups ni restore.
- El [PR #983](https://github.com/pl0n3r/GrindFlow/pull/983) quedó reconciliado con `main` mediante actualización de rama; en HEAD `b0898f6d84b201245bac5425d2ff81588fb3162f`, `behind(main)=0`, diff exclusivo de este documento, reserva V3 `a6479d2b-57d9-4d38-b29b-de5a50160b03` auténtica. Se reparó la validación de coordinación con la referencia `Closes #917`; se acreditaron `validate`, Factory `Validar`, CodeQL, SonarCloud y aceptación `success` sobre ese HEAD. El reintento de **Política Factory** siguió **`failure`**, error `Timeline requiere exactamente un reintento OWNER del HEAD`, al no haber revisión final CodeRabbit. Este historial de CI solo corresponde a `b0898f6d` y **se debe revalidar** si el archivo recibe un nuevo commit.
- **Aceptación diferencial pendiente:** AC-01 sigue parcial por identidad HTTP Symfony exacta; AC-02 sigue parcial por ausencia de excepción/stack histórico sanitizado que pruebe la causa 500 y lo distinga causalmente del 404 de #903; AC-03 sigue parcial por falta de comprobante independiente de integridad y restaurabilidad del respaldo `var/cache/prod.bak-20261105`, así como de rollback. La correlación temporal con `cache:clear` no equivale a causa raíz del 500 ni a verificación de restauración.
- **Autoridad y reversibilidad:** no se ha ejecutado nueva escritura de Hostinger; la autorización OWNER #6686368279 es **solo lectura sin datos privados**. Para cualquier mutación de producción se requiere consentimiento específico adicional, backup restaurable y rollback D-043/D-059. PR permanece **DRAFT** hasta completar AC-01..03 y la revisión CodeRabbit **FINAL única** con CI terminal verde del HEAD definitivo; **NO MERGE** por el mero éxito del diagnóstico.

## Corte de evidencia incorporado en el informe — 7-nov-2026, 03:06 UTC
<!-- GF917-HTTP-S4-EXACTHEAD-20261107T0306Z -->

- **Proveniencia.** Desde equipo del propietario conectado se ejecutaron cinco `GET` públicos sin sesión ni cookies, usando un cliente que solo imprimió HTTP/MIME y claves JSON explícitamente permitidas; no hay cuerpos privados ni escritura de servidor. Observaciones del 2026-11-07 entre **03:06:39 y 03:06:43 UTC**: `/s4/api/admin/context`, `/s4/api/admin/vault` y `/s4/api/admin/rules/weekly/preview` respondieron individualmente **401 `application/json`**, con `error.code=authentication_required`. Se revalidó recuperación funcional de los 500 históricos, **no** su causa.
- **Identidad.** `/health` respondió **200** JSON, `version=0.1.227`, `commit=f0da143fa242f202e03becea4c4aa5690e1ebbd9`, `exact=true`; esto identifica el runtime **Laravel raíz** y coincidía con GrindFlow `main` en la comprobación, no certifica el backend Symfony que atiende `/s4`. `/s4/health` respondió **200** JSON, `version=0.1.227`, `stage=s0-preview`, **`release_sha=null`**. Un GET público suplementario de cabeceras no encontró identificador alternativo `commit/release/version/sha` en esas respuestas S4; la correlación de SHA HTTP Symfony **sigue UNKNOWN**.
- **Calidad y checks del HEAD anterior a esta anotación.** Sobre `e35c93d51a6167eab13ea33085b9249a75ece444`, `validate`, `Factory CI reusable / Validar`, SonarCloud y CodeQL fueron terminales **success**. `Factory policy / Validar decisiones y límite de revisión` fue **failure**, con diagnóstico de timeline que exige una revisión OWNER de HEAD; `CodeRabbit` resultó **skipped** por DRAFT. Esta constatación **no es válida por herencia para el commit que incorpore esta sección**: en ese nuevo HEAD se deberán verificar nuevamente todos los checks.
- **Seguridad de la revisión.** El [comentario de CodeRabbit #6701471555](https://github.com/pl0n3r/GrindFlow/issues/917#issuecomment-6701471555) leyó el documento sobre `e35c93d5` y coincidió en que faltan las evidencias operativas, pero indicó expresamente que **no es una revisión FINAL**. No equivale a una revisión formal exact-HEAD ni a la excepción D-070 por límite de cuota. La petición `@coderabbitai review` continúa reservada para el PR definitivo con CI verde.
- **Criterios pendientes con vía de desbloqueo concreta.** AC-01 requiere trazabilidad autenticada entre el servicio **Symfony HTTP** y un SHA exacto, no solo `git rev-parse HEAD` del checkout inspeccionado por [diagnóstico S4 #42327484908](https://github.com/pl0n3r/GrindFlow/actions/runs/42327484908). AC-02 requiere una excepción histórica sanitizada de los **500** o un diagnóstico equivalente que sustente causalidad y distinga el 404 de #903; la correlación con el `cache:clear` no basta. AC-03 acredita 3×401 pero exige recibos verificables de integridad/restaurabilidad del backup de caché declarado `var/cache/prod.bak-20261105` y del rollback. El consentimiento OWNER [#6686368279](https://github.com/pl0n3r/GrindFlow/issues/917#issuecomment-6686368279) permite **solo lectura de logs sin datos privados**; cualquier escritura, restore, restart o deploy sigue necesitando autorización **específica**, backup verificado y reversión. Sin estos datos se mantiene **NO MERGE/NO GO**, incluso si el nuevo CI vuelve a verde.


## Sondeo HTTPS independiente, read-only — 7-nov-2026 12:22:55 UTC
<!-- GF917-INDEPENDENT-READONLY-20261107T122255Z -->

**Acción nueva ejecutada desde el equipo conectado autorizado** (macOS, 7-nov-2026, inicio `2026-11-07T12:22:55.681115+00:00`): seis solicitudes `GET` HTTPS a `https://www.grindflow.com.co`, `Accept: application/json`, sin cookies, sesión, tokens, `POST`, SSH, redirecciones, escritura en Hostinger ni datos personales. El cliente validó TLS y **solo** imprimió HTTP, MIME y una allowlist de campos JSON; el resultado del proceso fue `exit code 0`. Se inspeccionaron como máximo 10 KiB de cada respuesta exclusivamente en memoria, sin persistir cuerpos.

| Endpoint | HTTP | MIME | Campos públicos permitidos |
| --- | ---: | --- | --- |
| `/s4/api/admin/context` | **401** | `application/json` | `error.code=authentication_required` |
| `/s4/api/admin/vault` | **401** | `application/json` | `error.code=authentication_required` |
| `/s4/api/admin/rules/weekly/preview` | **401** | `application/json` | `error.code=authentication_required` |
| `/health` | **200** | `application/json` | `status=ok; version=0.1.227; commit=f0da143fa242f202e03becea4c4aa5690e1ebbd9; exact=true` **solo Laravel raíz** |
| `/s4/health` | **200** | `application/json` | `status=ok; version=0.1.227; stage=s0-preview; release_sha=null` |
| `/s4/_bridge-readiness` | **200** | `application/json` | sin campos de identidad en la allowlist `sha/commit/version/release_sha` |

**Interpretación fail-closed:** el 500 anónimo no se reproduce en este corte (3/3 devuelven 401 JSON). **AC-01 sigue PARCIAL**: la identidad del servidor HTTP **Symfony** es `UNKNOWN` porque `release_sha=null`, aunque el commit Laravel coincida con `main`. **AC-02 sigue PARCIAL**: no se ha recuperado clase/stack sanitizado del error histórico; no extrapolar la causa del 404 de #903. **AC-03 sigue PARCIAL**: ninguna prueba de integridad o ensayo de restauración del backup ni autorización de escritura/rollback; este GET no es una reparación productiva. Los checks CI anteriores no certifican esos tres AC. No se pidió CodeRabbit; mantener PR DRAFT y NO MERGE hasta evidencia operacional y autorización real conforme al Issue.


## Revalidación pública independiente — 2026-11-07 14:23:38 UTC
<!-- GF917-READONLY-142338Z-B2A65836 -->

Desde el dispositivo conectado se ejecutó otra lectura HTTPS GET, sin sesión ni cookies, usando cliente con validación TLS. Los cuerpos se procesaron en memoria y solo se imprimieron status, MIME y campos allowlisted; no se persistieron respuestas.

| Endpoint | HTTP | MIME | Campo público permitido |
| --- | ---: | --- | --- |
| `/s4/api/admin/context` | **401** | `application/json` | `error.code=authentication_required` |
| `/s4/api/admin/vault` | **401** | `application/json` | `error.code=authentication_required` |
| `/s4/api/admin/rules/weekly/preview` | **401** | `application/json` | `error.code=authentication_required` |
| `/health` | **200** | `application/json` | `status=ok; version=0.1.227; commit=f0da143fa242f202e03becea4c4aa5690e1ebbd9; exact=true` (**Laravel raíz**) |
| `/s4/health` | **200** | `application/json` | `status=ok; version=0.1.227; stage=s0-preview; release_sha=null` |
| `/s4/_bridge-readiness` | **200** | `application/json; charset=utf-8` | sin campos públicos de identidad en la allowlist |

**Resultado incremental:** la recuperación superficial de las tres respuestas anónimas 401 JSON se mantiene. No cambia el dictamen de aceptación: AC-01 sigue parcial porque el proceso HTTP Symfony no publica un SHA exacto; AC-02 sigue parcial porque no hay excepción histórica sanitizada o recibo autorizado de indisponibilidad; AC-03 sigue parcial porque no existe prueba independiente de integridad/restauración/rollback del backup `var/cache/prod.bak-20261105`. La respuesta 200 de Laravel no prueba la identidad del proceso Symfony. Esta lectura no ejecutó SSH, escritura, reinicio, `cache:clear`, restore ni deploy. El permiso OWNER vigente sigue limitado a lectura; no se solicitó CodeRabbit ni se autorizó merge.
