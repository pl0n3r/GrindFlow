# GrindFlow #903 — reconciliación de rutas S4 en producción

> Estado: **INCIDENTE ABIERTO / NO RESUELTO**. Este documento registra observaciones auténticas de solo lectura. No acredita un despliegue correctivo, una validación en producción ni el cumplimiento de AC-01/AC-02.

## Identidad y fuentes

- Issue: [GrindFlow #903](https://github.com/pl0n3r/GrindFlow/issues/903).
- Fuente GitHub `main` examinada: `f2a12918772bf840ea6086104ef1718dceac52a0` (versión `0.1.225`).
- Destino inspeccionado: `https://www.grindflow.com.co`; sondeos GET sin credenciales, cookies, secretos ni escritura.
- Evidencia independiente del host observada el **2026-11-04T02:05:18Z**. Se conserva el estado HTTP y solamente campos públicos de readiness.
- `/health`: **200**, `commit=f2a12918772bf840ea6086104ef1718dceac52a0`, `exact=true`.
- `/s4/health`: **200**, versión `0.1.225`, `release_sha=null`.
- `/s4/_bridge-readiness`: **200**, `data.state=ready_for_web_probe`. Esto acredita el bridge, **no** la lista de rutas ni el registro exacto de controladores Symfony.

## Matriz HTTP: estado observado, no objetivo simulado

La auditoría que originó el issue detectó los 404. Un primer sondeo independiente los confirmó; la comprobación fechada abajo se hizo **después** de la última ejecución de `S4 Runtime Prepare`. No existe en esta evidencia una medición autenticada inmediatamente anterior a esa ejecución, por lo que no se presenta la tabla como una comparación causal antes/después del workflow.

| Ruta relativa a `/s4` (GET) | Auditoría inicial (#903) | Confirmación UTC 02:05:18, después de prepare | Resultado exigido para AC-01 |
| --- | --- | --- | --- |
| `/api/admin/library/collections` | 404 | **404** | 401, 302 o 200 |
| `/api/admin/library/permissions` | 404 | **404** | 401, 302 o 200 |
| `/api/admin/plans` | 404 | **404** | 401, 302 o 200 |
| `/api/admin/rules/automation` | 404 | **404** | 401, 302 o 200 |
| `/api/admin/reports/cross-module` | 404 | **404** | 401, 302 o 200 |
| `/api/admin/privacy/data-subject-requests/csrf` | 404 | **404** | 401, 302 o 200 |
| `/privacidad-y-mis-derechos` | 404 | **404** | 401, 302 o 200 |

Un HTTP `200` de readiness y un workflow verde **no** satisfacen AC-01: las siete rutas continúan en 404.

## Ejecución técnica comprobada y alcance de la causa

- [GrindFlow Deploy Observer #41755753559](https://github.com/pl0n3r/GrindFlow/actions/runs/41755753559): `completed/success` en `f2a12918…`; sus jobs de identidad de `main` y observación de checkout terminaron en success.
- [S4 Runtime Prepare #41755830530](https://github.com/pl0n3r/GrindFlow/actions/runs/41755830530), iniciado el 2026-11-04T01:42:01Z: `completed/success`, job `exact-s4-runtime-prepare` y sus pasos de lectura de SHA, preparación remota de dependencias y consulta de bridge en success. El log auténtico contiene `S4_RUNTIME_PREPARE_OK` a las 01:42:13Z.
- La evidencia **descarta que baste repetir el mismo prepare de Composer**: se ejecutó correctamente en el SHA observado y las rutas siguen fallando 23 minutos después.
- En `main`, `symfony/config/routes.yaml` importa atributos de `../src/Http/Controller/`. Se comprobaron las anotaciones de rutas de `LibraryCollectionsController`, `PlanLimitsAdminController`, `PublishingAutomationController`, `DataSubjectRequestsController` y `PrivacyRightsController`. El árbol fuente contiene las rutas afectadas; no se ha demostrado que el mapa de rutas activo del host las contenga.
- **Causa raíz aún no confirmada**: candidatas a contrastar sin escribir incluyen caché persistente de rutas/container Symfony (`symfony/var/cache/prod`), archivos de controladores faltantes en el checkout efectivo, OPCache desactualizado o un desajuste de configuración/namespace del runtime. El script `scripts/prepare-s4-runtime.sh` instala y promueve `symfony/vendor` con rollback, pero **no ejecuta limpieza/reconstrucción de caché Symfony**; esto es evidencia de una posible brecha, no prueba concluyente.
- El diagnóstico siguiente debe cotejar **en el host autorizado** `git rev-parse HEAD`, presencia/hash de los controladores, fecha/identidad de `symfony/vendor` y caché, `bin/console debug:router --env=prod` frente a los atributos, y PHP/OPCache. Registrar solamente estado, hashes, tiempos y nombres no sensibles; jamás copiar `.env`, claves, datos personales o logs privados.

## AC-03: por qué `release_sha` continúa nulo

En `symfony/src/Http/Controller/HealthController.php`, el campo `release_sha` procede de `GrindFlow\Shared\Version\ProductVersion::releaseSha()`. El método lee el marcador privado `.release-sha` desde la raíz del checkout y devuelve `null` si falta, es un enlace simbólico o su contenido no es un SHA hexadecimal de 40 caracteres válido. **No se ha inspeccionado el fichero remoto para distinguir esas causas**. El SHA observado en `/health` corresponde al contrato de Laravel y no se transporta automáticamente como prueba de release de Symfony.

## Reconciliación diferencial de main y alcance — 2026-11-04 09:29 UTC
<!-- GF903-S4-CURRENTMAIN-A1E985B-20261104 -->

- **Nuevo SHA de fuente, distinto del corte inicial:** `GrindFlow/main@a1e985b39b31211a48eeddbcaaa78cd2101011b1`. La matriz de 02:05:18Z sigue siendo una observación **histórica** sobre el corte anterior `f2a12918…`; ninguna de sus siete celdas debe promoverse a medición posterior ni declararse reparada sin otra sonda GET real.
- **Preparación remota posterior comprobada:** [S4 Runtime Prepare #41792542574](https://github.com/pl0n3r/GrindFlow/actions/runs/41792542574) terminó `success` en ese SHA nuevo; job `exact-s4-runtime-prepare` ejecutó SSH/Composer, registró `S4_RUNTIME_PREPARE_OK` a 2026-11-04T07:28:57Z, revalidó `main` y completó la verificación del bridge. El helper [`prepare-s4-runtime.sh`](../../scripts/prepare-s4-runtime.sh) **no** reconstruye `symfony/var/cache/prod`. Este hito acredita preparación de dependencias, **no** las siete rutas HTTP ni el mapa `debug:router` productivo; todavía faltan HTTP GET posteriores al job y correlación sanitizada con el host.
- **Excepción de producto en AC-01 — causa estática confirmada distinta del despliegue:** `/api/admin/reports/cross-module` está definido en [`CrossModuleReportController.php`](../../symfony/src/Http/Reports/CrossModuleReportController.php), **fuera** del único directorio importado por [`symfony/config/routes.yaml`](../../symfony/config/routes.yaml), `../src/Http/Controller/`. El propio controlador advierte que activar routing exige un cambio reclamado aparte. Así, esa **séptima ruta no podrá devolver 401/302/200 únicamente regenerando caché, Composer u OPCache**, incluso con un host sincronizado. Su activación depende de [roadmap #348](https://github.com/pl0n3r/GrindFlow/issues/348), todavía planificado, y de un contrato/claim técnico independiente. **No introducir la ruta de producto en el PR documental #907** para forzar AC-01. AC-01, redactado como siete rutas obligatorias, contiene esa dependencia de producto y requiere decisión legítima para corregir el alcance sin relajar los seis diagnósticos de runtime.
- **Seis rutas atribuibles a verificación de runtime:** `/api/admin/library/collections`, `/api/admin/library/permissions`, `/api/admin/plans`, `/api/admin/rules/automation`, `/api/admin/privacy/data-subject-requests/csrf` y `/privacidad-y-mis-derechos`: los controladores están en el import declarado, pero la causa del 404 del **router efectivo del host** sigue **NO_VERIFICABLE** sin `debug:router --env=prod`, hashes/archivos de checkout y evidencia de caché/OPCache sanitizada y autorizada.
- **DSR_FAIL_CLOSED_TEST_ONLY — segunda excepción real del contrato AC-01:** en [`DataSubjectRequestsController.php`](../../symfony/src/Http/Controller/DataSubjectRequestsController.php), `csrf()` devuelve **404 JSON `feature_unavailable` ANTES de evaluar autenticación** en cualquier `APP_ENV` distinto de `test` (método `enabled()`). Por tanto, un router S4 reparado **no** debe devolver 401/302/200 para `/api/admin/privacy/data-subject-requests/csrf` en producción; el 404 JSON intencional es distinto del 404 **genérico de Symfony** que se observó en #903. Habilitar DSR real vulneraría el bloqueo de privacidad/legal de [#457](https://github.com/pl0n3r/GrindFlow/issues/457). La reparación de runtime debe registrar **el tipo de 404**, sin tratar este endpoint como disponible; además de Reports, el literal «7 rutas dejan de devolver 404» de AC-01 requiere una revisión de aceptación auténtica, no un cambio que exponga funciones deshabilitadas.
- **Acceso y límites:** la sesión solo tiene GitHub de lectura/escritura, **no un canal autenticado aprobado de diagnóstico SSH/hPanel** para #903 ni constancia de backup/restore anterior a intervención. Las consultas públicas desde este entorno no resuelven DNS de `www.grindflow.com.co` y el lector web no obtiene la URL; no se efectuó una lectura actual de las rutas. Un workflow `success` o CI `validate` verde no convierte esa ausencia en `VALIDATED_IN_PRODUCTION`. No repetir el prepare productivo ni alterar Symfony/Hostinger/caché sin permiso/backup; no abrir otra hoja duplicada para #348.
- **Próxima acción ejecutable:** obtener autorización específica para `debug:router` y lectura sanitaria de SHA, controladores y caché en el checkout actual; separar seis 404 de la exclusión intencional de Reports. Tras un cambio reversible expresamente aprobado, repetir GET y anotar hora, SHA, los seis HTTP y su causa; tramitar AC-01 conforme al contrato renovado **con autoridad**, luego pasar CI exact-HEAD y una sola revisión CodeRabbit FINAL antes de quitar DRAFT.

## Sondeo público nuevo y discriminación del router — 2026-11-04T09:38:48Z
<!-- GF903-S4-GET-9H38-20261104 -->

Se ejecutaron **diez GET públicos de solo lectura**, sin cookies, credenciales, seguimientos de redirecciones ni persistencia del HTML, sobre `https://www.grindflow.com.co` desde un equipo conectado y autorizado. El código de diagnóstico limitó la lectura a 16 KiB por respuesta y registró solo ruta, HTTP, clase MIME y el indicador de página genérica Symfony. El instante es UTC del equipo que efectuó la comprobación; el sondeo no autentica acceso privado a Hostinger.

| GET | HTTP nuevo | MIME | Evidencia acotada |
| --- | --- | --- | --- |
| `/health` | 200 | `application/json` | `exact=true`, `commit=main@a1e985b39b31211a48eeddbcaaa78cd2101011b1` |
| `/s4/health` | 200 | `application/json` | `version=0.1.225`, `release_sha=null` |
| `/s4/_bridge-readiness` | 200 | `application/json` | `data.state=ready_for_web_probe` |
| `/s4/api/admin/library/collections` | **404** | `text/html` | Symfony genérico = true |
| `/s4/api/admin/library/permissions` | **404** | `text/html` | Symfony genérico = true |
| `/s4/api/admin/plans` | **404** | `text/html` | Symfony genérico = true |
| `/s4/api/admin/rules/automation` | **404** | `text/html` | Symfony genérico = true |
| `/s4/api/admin/reports/cross-module` | **404** | `text/html` | Symfony genérico = true; ruta fuera de import en fuente |
| `/s4/api/admin/privacy/data-subject-requests/csrf` | **404** | `text/html` | Symfony genérico = true; **no** es el JSON `feature_unavailable` que produciría el controlador test-only |
| `/s4/privacidad-y-mis-derechos` | **404** | `text/html` | Symfony genérico = true |

**Comparación con 02:05:18Z:** las siete rutas siguen devolviendo un 404 genérico tras [`S4 Runtime Prepare` #41792542574](https://github.com/pl0n3r/GrindFlow/actions/runs/41792542574), ejecutado sobre `main@a1e985b…` a las 07:28 UTC. La coincidencia temporal demuestra que la preparación de dependencias **no reparó el registro HTTP**, pero **no identifica por sí sola** caché, PHP OPcache, archivos del checkout o configuración como causa raíz. Las cinco rutas funcionales y el endpoint DSR deben ser diagnosticados en el mapa de rutas efectivo de producción; el controlador Reports permanece excluido **por diseño**. Un `release_sha=null` no permite atribuir la identidad exacta del proceso Symfony aunque Laravel coincida con `main`.

**Resultado de aceptación:** AC-01 sigue **NO CUMPLIDO**; AC-02 sigue **NO CUMPLIDO** mientras no exista diagnóstico autenticado y reparación verificada; AC-03 tiene explicación de código pero no marcador remoto válido; AC-04 debe revalidarse sobre el HEAD documental definitivo. No se ejecutó escritura, limpieza de caché, migración, cambio de entorno ni despliegue.

## Reentrada, controles y rollback

1. Antes de una consulta autenticada por SSH/hPanel o cualquier escritura, obtener autorización específica y evidencia de backup/restore y reversión según [AGENTS.md](../../AGENTS.md), [docs/DEPLOY-HOSTINGER.md](../DEPLOY-HOSTINGER.md), D-043/D-059 y el alcance explícito de #903. **No se ha otorgado ni usado ese acceso en esta conciliación.**
2. Si el diagnóstico demuestra caché Symfony obsoleta, preparar un procedimiento controlado para reconstruir rutas/contenedor, preservando snapshot para rollback y sin migraciones, cambios de `.env` ni datos reales; tramitar cualquier ampliación de claims y gate antes de cambiar scripts/workflows.
3. Tras una reparación auténtica, tomar otro sondeo GET de las siete rutas y documentar los estados HTTP antes/después, el SHA exacto, comando/workflow, recibo del backup y resultado del rollback si fuera necesario. El objetivo es 401/302/200 en las siete; un 404 residual es NO GO.
4. Pasar `GrindFlow CI / validate` sobre HEAD exacto y la revisión final CodeRabbit **una sola vez**, únicamente cuando el cambio esté completo y los gates aplicables estén en verde.

**Estado de aceptación actual:** AC-01 pendiente (7/7 rutas 404); AC-02 pendiente (hipótesis sin causa raíz autenticada y sin reparación); AC-03 explicación de `release_sha=null` documentada, sin declarar marca de release real; AC-04 condicionado a `validate` terminal en el HEAD exacto del PR (sin sustituir AC-01 ni AC-02). Mantener #903 abierto y cualquier PR de preparación en DRAFT hasta superar los gates.

## Medición posterior al último `main` — 2026-11-04 11:29 UTC

<!-- GF903-PUBLIC-GET-112917-20261104 -->

**Sondeo nuevo de solo lectura en equipo autorizado** a las `2026-11-04T11:29:17Z`: diez peticiones GET públicas, `Cache-Control: no-cache`, sin credenciales, cookies, seguimiento de redirecciones ni escritura. La herramienta registró solo HTTP y MIME. La lectura acotada de los tres JSON de salud fue posterior y publicó exclusivamente las claves permitidas. No hubo acceso SSH ni mutación en Hostinger.

| Ruta GET | HTTP | Tipo |
| --- | ---: | --- |
| `/health` | 200 | application/json |
| `/s4/health` | 200 | application/json |
| `/s4/_bridge-readiness` | 200 | application/json |
| `/s4/api/admin/library/collections` | **404** | text/html |
| `/s4/api/admin/library/permissions` | **404** | text/html |
| `/s4/api/admin/plans` | **404** | text/html |
| `/s4/api/admin/rules/automation` | **404** | text/html |
| `/s4/api/admin/reports/cross-module` | **404** | text/html |
| `/s4/api/admin/privacy/data-subject-requests/csrf` | **404** | text/html |
| `/s4/privacidad-y-mis-derechos` | **404** | text/html |

Identidades públicas verificadas: Laravel `/health` afirma `exact=true`, `commit=3165f487b2fe705a9e55f59834732cb0fdafd62f`, `version=0.1.225`; Symfony `/s4/health` indica `version=0.1.225`, `release_sha=null`; bridge `data.state=ready_for_web_probe`.

Última preparación observada para este `main`: [S4 Runtime Prepare #41820162988](https://github.com/pl0n3r/GrindFlow/actions/runs/41820162988), `completed/success` sobre `3165f487b2fe705a9e55f59834732cb0fdafd62f` (iniciada 11:21 UTC). El resultado de GET 11:29 demuestra **7/7 HTTP 404 posteriores**; el prepare no acredita registro efectivo de rutas. [#920](https://github.com/pl0n3r/GrindFlow/issues/920) ya entregó y fusionó en [#921](https://github.com/pl0n3r/GrindFlow/pull/921) el workflow manual owner-only `S4 Router Diagnostics`, que permite diagnóstico SSH categórico usando credenciales solo en el runner. [#922](https://github.com/pl0n3r/GrindFlow/issues/922) también quedó integrado con tests de puertos/cache; **no existe en esta evidencia una ejecución de `S4 Router Diagnostics` sobre el host**. No interpretar el prepare, ni un smoke 200, como prueba de reparación.

**AC-01: NO CUMPLIDO.** Las siete respuestas siguen siendo HTML 404; Reports `/api/admin/reports/cross-module` permanece fuera del import de rutas por decisión de producto [#348](https://github.com/pl0n3r/GrindFlow/issues/348), mientras DSR-CSRF requiere conservar 404 JSON `feature_unavailable` por la puerta [#457](https://github.com/pl0n3r/GrindFlow/issues/457) aun tras arreglar el router. El literal «siete no-404» no puede imponerse sin alterar alcance/producto/privacidad. **AC-02: NO CUMPLIDO:** todavía no hay causa raíz de runtime autenticada ni repair autorizado con backup y sondeo posterior. **AC-03:** se explica `release_sha=null`, pero falta corroborar marcador remoto. **AC-04:** CI del PR deberá corresponder al HEAD reconciliado final.

Próxima comprobación segura: un disparo owner-only de `S4 Router Diagnostics` en el `main` exacto, con autorización específica para diagnóstico SSH de lectura. Contrastar indicadores `CHECKOUT`, hashes `CONTROLLER_*`, `AUTOLOAD`, `CACHE_ROUTE_*` y `RELEASE_MARKER` con este sondeo; no tocar `.env`, datos, migraciones, cachés ni secretos. Mantener PR #907 DRAFT y sin CodeRabbit hasta satisfacer la puerta operativa independiente y un contrato de aceptación legítimo.


## Ejecución autenticada del workflow owner-only — 2026-11-04 11:34–11:36 UTC

<!-- GF903-DIAGNOSTIC-RUN-41821996322-20261104 -->

Se despachó **una vez** [S4 Router Diagnostics #41821996322](https://github.com/pl0n3r/GrindFlow/actions/runs/41821996322) mediante la cuenta GitHub owner `pl0n3r`, en `workflow_dispatch` contra el `main` exacto `3165f487b2fe705a9e55f59834732cb0fdafd62f`. El workflow está limitado a **SSH de solo lectura** y nunca solicita `bin/console`, Composer, reinicio, migraciones, caché ni despliegue.

- Intento 1: `completed/failure`. En job `owner-only-s4-router-readonly`, los pasos de preflight exact-main, checkout y `bash -n` del helper fueron `success`. El paso `Inspect Hostinger without production writes` fue `failure` con error sanitizado `S4 read-only inspection failed; details withheld.`.
- Se reintentaron **una sola vez** los jobs fallidos mediante GitHub Actions; intento 2: `completed/failure` en el **mismo** paso; el resto de preflights permaneció verde. No hay reporte categórico `S4_DIAG_*` confiable del remoto.
- El workflow suprime intencionalmente stderr y contenido remoto no validado, por lo que **no se puede deducir** si el fallo ocurrió por autenticación, red, checkout remoto desactualizado, ejecución del helper o alguna condición segura de rechazo. No inventar `DRIFT` ni `MATCH`. Tampoco se imprimieron rutas privadas ni secretos en los resultados del incidente.
- Dos fallos repetidos con idéntico preflight verde desaconsejan más reruns sin una corrección dirigida. La herramienta requiere un diagnóstico de transporte/autorización sanitizado adicional o acceso de operador autorizado; no extraer llaves ni realizar cambios productivos desde esta sesión.

La evidencia HTTP pública de las **siete respuestas 404** al 11:29:17Z permanece válida e independiente de la falta de salida SSH. **AC-01/AC-02 permanecen NO CUMPLIDOS**. El ticket y PR deben seguir abiertos, el PR en DRAFT, y CodeRabbit se reserva para el HEAD definitivo con las puertas operativas satisfechas.


## Conciliación de rama con el último main y diagnóstico SSH (4 de noviembre de 2026)

<!-- GF903-S4-MAIN-RECONCILED-177300B2-20261104 -->

- **Main de código verificado:** \`177300b29249b9deea5e5100967e56b8dd1758e9\`, squash del [PR #925](https://github.com/pl0n3r/GrindFlow/pull/925), que dejó cerrado/completado el blocker [#924](https://github.com/pl0n3r/GrindFlow/issues/924). Ese cambio solamente clasifica fallos SSH (255/1/2/otro) en el workflow de diagnóstico owner-only y amplía pruebas sintéticas; **no se realizó reparación del router S4**.
- [S4 Runtime Prepare #41826197735](https://github.com/pl0n3r/GrindFlow/actions/runs/41826197735) terminó \`success\` con ese main según GitHub Actions, pero no existe en este corte una medición HTTP independiente posterior al nuevo SHA ni evidencia \`VALIDATED_IN_PRODUCTION\`. **No se afirma que las siete rutas hayan cambiado.**
- La última medición HTTP categórica documentada sigue siendo la de **2026-11-04T11:29:17Z** sobre el main anterior \`3165f487…\`: 7/7 HTTP 404 HTML genéricos, \`/health\` exact=true, bridge listo y \`release_sha=null\`. Esa medición se conserva como **histórica** y no se imputa al main \`177300b2…\`.
- La ejecución previa [S4 Router Diagnostics #41821996322](https://github.com/pl0n3r/GrindFlow/actions/runs/41821996322) falló dos veces antes de producir categorías remotas autenticadas. No repetirla de manera indiscriminada ni adjudicar causa de autenticación/caché. La nueva clasificación de #925 solo aumenta observabilidad en **una futura ejecución específicamente autorizada**.
- La ruta Reports continúa fuera de \`symfony/config/routes.yaml\` por el alcance de producto #348, y DSR-CSRF debe seguir fallando cerrado fuera de tests por la puerta legal #457. **AC-01 literal (7 rutas no-404) requiere una decisión explícita del propietario**, no activar funciones deshabilitadas para falsear resultados.
- **Puertas sin superar:** AC-01/AC-02 productivos, diagnóstico real autorizado, recibo previo de backup/restore y rollback para cualquier escritura en Hostinger, y aceptación de producto/privacidad. Mantener Issue #903 abierto y PR #907 DRAFT; la reconciliación de la rama **no es un despliegue ni valida producción**.
- **Coordinación:** reserva V3 auténtica \`cbb53f02-a68b-4d1d-b118-4dfc4efea06d\` de \`trabajo/issue-903\`; referencia \`coordinacion/lock-issue-903\` preexistente. No borrar ni forzar la referencia; resolver con protocolo V3 si llega a reunirse evidencia de recuperación.


## HTTP público post-merge confirmado — 4 de noviembre de 2026, 12:12–12:13 UTC

<!-- GF903-PUBLIC-GET-121206-EXACTMAIN-177300B2-20261104 -->

Sondeo GET de solo lectura desde un dispositivo autorizado con acceso público, sin cookies, credenciales, redirecciones seguidas, escritura ni invocación SSH. Se usó \`Cache-Control: no-cache\` y únicamente se conservaron método/ruta, status y MIME; la lectura JSON de tres endpoints de salud fue filtrada a \`commit/exact/version/release_sha/data.state\`.

| Ruta GET pública | HTTP | MIME |
| --- | ---: | --- |
| \`/health\` | **200** | \`application/json\` |
| \`/s4/health\` | **200** | \`application/json\` |
| \`/s4/_bridge-readiness\` | **200** | \`application/json\` |
| \`/s4/api/admin/library/collections\` | **404** | \`text/html\` |
| \`/s4/api/admin/library/permissions\` | **404** | \`text/html\` |
| \`/s4/api/admin/plans\` | **404** | \`text/html\` |
| \`/s4/api/admin/rules/automation\` | **404** | \`text/html\` |
| \`/s4/api/admin/reports/cross-module\` | **404** | \`text/html\` |
| \`/s4/api/admin/privacy/data-subject-requests/csrf\` | **404** | \`text/html\` |
| \`/s4/privacidad-y-mis-derechos\` | **404** | \`text/html\` |

Identidad JSON pública obtenida en segunda consulta a las **12:13:22Z**:
- \`/health\`: \`{"commit":"177300b29249b9deea5e5100967e56b8dd1758e9","exact":true,"version":"0.1.225"}\`.
- \`/s4/health\`: \`{"release_sha":null,"version":"0.1.225"}\`.
- \`/s4/_bridge-readiness\`: \`{"state":"ready_for_web_probe"}\`.

Esta medición **sí** corresponde al nuevo main de código \`177300b2…\` y es posterior al [S4 Runtime Prepare #41826197735](https://github.com/pl0n3r/GrindFlow/actions/runs/41826197735) en \`success\`. Confirma que el flujo de preparación no hizo disponibles las rutas AC-01; **no** certifica la causa del 404 ni un release SHA válido de Symfony. Dos rutas siguen ligadas a decisiones de producto y privacidad (#348 Reports no importado, #457 DSR protegido test-only) y no se deben abrir para simular un éxito.

**Criterios al cierre de esta medición:** AC-01 = NO CUMPLIDO (7/7 HTTP 404); AC-02 = NO CUMPLIDO (causa S4 remota no diagnosticada y no existe repair autenticado); AC-03 = explicación documental de \`release_sha=null\`, no comprobación de archivo remoto; AC-04 = revalidar sobre HEAD definitivo de PR #907. **No se ejecutó SSH ni se tocó producción** desde este cambio documental; no se usaron secretos ni datos reales. El PR sigue DRAFT.


## Diagnóstico SSH owner-only exact-main ejecutado (4-nov-2026, 12:22 UTC)

<!-- GF903-SSH-RUN-41828650940-REMOTE_EXIT_1-20261104 -->

- **Acción autorizada de solo lectura:** se disparó el workflow [S4 Router Diagnostics #41828650940](https://github.com/pl0n3r/GrindFlow/actions/runs/41828650940) vía GitHub CLI autenticado como \`pl0n3r\`, sobre \`main@177300b29249b9deea5e5100967e56b8dd1758e9\` con \`workflow_dispatch\`; \`actor\` y \`triggering_actor\` del run son ambos \`pl0n3r\`.
- **Pasos verificados en GitHub:** preflight owner+SHA exacto \`success\`; checkout \`success\`; \`bash -n scripts/s4-router-diagnostics.sh\` \`success\`; \`Inspect Hostinger without production writes\` **failure**, categoría de error sanitizada \`REMOTE_EXIT_1\` (log del job \`126569829977\` a 12:22:12 UTC). La revalidación de main posterior quedó \`skipped\` por el fallo. \`run_attempt=1\`; no se reintentó.
- **Interpretación limitada:** salida SSH remota y stderr permanecen ocultos. Un exit 1 es compatible con \`S4_DIAG_CHECKOUT=DRIFT\` por el helper, pero también con otros fallos remotos; **NO se confirma deriva del checkout ni fallo de caché ni autenticación**. La herramienta actual descarta el texto categórico seguro del helper al fallar; un nuevo blocker [#926](https://github.com/pl0n3r/GrindFlow/issues/926) busca discriminar únicamente el marker exacto sin filtrar stdout arbitrario.
- **Alcance:** no hubo migración, SQL, deploy, limpieza de caché, escritura sobre Hostinger, lectura de \`.env\`, datos reales, impresión de secretos ni cambio de runtime. El workflow es de inspección.
- **Aceptación:** AC-01 sigue 7/7 HTTP 404 en la última comprobación exact-main de 12:12 UTC; AC-02 aún carece de causa autenticada y reparación certificada; AC-03 explica \`release_sha=null\` sin marker remoto verificado. **No fusionar ni solicitar CodeRabbit FINAL** sobre una matriz aún sin validación productiva.


## Incorporación del blocker #926 y observación de propagación del deploy — 4-nov-2026

<!-- GF903-PR927-MERGED-MAIN98AF-PRODUCTION-OLD-124155-20261104 -->

- [PR #927](https://github.com/pl0n3r/GrindFlow/pull/927) se integró por squash en \`main@98af3115c42ea2aa45018ff78cebb98977c2075c\` y [#926](https://github.com/pl0n3r/GrindFlow/issues/926) quedó \`CLOSED\`. Workflow owner-only \`S4 Router Diagnostics\`: si SSH retorna código 1 y stdout es **exactamente** \`S4_DIAG_CHECKOUT=DRIFT\`, publicará solo la categoría fija \`CHECKOUT_DRIFT\`; otros retornos se mantienen opacos. Es un cambio de observabilidad **sin despliegue ni repair del runtime S4**.
- D-070 aplicado al PR auxiliar: \`validate\`, \`Factory CI reusable / Validar\`, CodeQL, SonarCloud y última Factory Policy terminales \`success\` en HEAD exacto \`f297b81d845ae1ac3345386d7e067d57d0b04646\`. Revisión CodeRabbit FINAL pedida una vez por OWNER el 12:36:48Z; el bot contestó \`Review rate limited\` a 12:37:41Z; no se reintentó ni se programó otra revisión. El squash fusionado no declara aceptación en producción.
- **GET público nuevo a 12:41:55 UTC** sin credenciales: \`/health\` indicó \`commit=177300b29249b9deea5e5100967e56b8dd1758e9\`, \`exact=true\`, \`version=0.1.225\`, mientras GitHub \`main=98af3115c42ea2aa45018ff78cebb98977c2075c\`. **El nuevo commit no estaba observado en salud productiva en ese instante.** No se atribuye causa; la próxima inspección SSH exact-main solo tendrá valor diagnóstico tras correlación de identidad productiva.
- La última medición íntegra de endpoints posterior al main anterior sigue siendo 12:12–12:13Z: siete respuestas 404 HTML genérico y S4 \`release_sha=null\`. No se afirma resultado nuevo de esas siete rutas sobre \`98af3115…\`.
- AC-01/AC-02 siguen **NO CUMPLIDOS**, y Reports (#348)/DSR (#457) conservan sus puertas de producto/privacidad. Sin cambio en Hostinger, caché, BD, archivos de configuración, datos reales ni credenciales. \`PR #907\` debe seguir \`DRAFT\` hasta causa, reparación con autorización/backups y HTTP posterior.


## Confirmación post-merge de #927: HEALTH exact-main y SSH genérico — 4-nov-2026

<!-- GF903-HTTP98AF-124344-ROUTER-FAIL-RUN41831886987 -->

**Identidad productiva recuperada** mediante GET de solo lectura, sin credenciales, \`2026-11-04T12:43:44Z\`: \`/health\` JSON \`commit=98af3115c42ea2aa45018ff78cebb98977c2075c\`, \`exact=true\`, \`version=0.1.225\`; \`/s4/health\` JSON \`release_sha=null\`, \`version=0.1.225\`. [S4 Runtime Prepare #41831615257](https://github.com/pl0n3r/GrindFlow/actions/runs/41831615257) terminó \`success\` sobre ese main. **Éxito de prepare y salud no son validación de rutas.**

**Siete GET públicos a \`2026-11-04T12:44:02Z\`, \`Cache-Control: no-cache\`, sin redirecciones ni credenciales:**

| GET bajo \`/s4\` | HTTP | MIME |
| --- | ---: | --- |
| \`/api/admin/library/collections\` | 404 | \`text/html\` |
| \`/api/admin/library/permissions\` | 404 | \`text/html\` |
| \`/api/admin/plans\` | 404 | \`text/html\` |
| \`/api/admin/rules/automation\` | 404 | \`text/html\` |
| \`/api/admin/reports/cross-module\` | 404 | \`text/html\` |
| \`/api/admin/privacy/data-subject-requests/csrf\` | 404 | \`text/html\` |
| \`/privacidad-y-mis-derechos\` | 404 | \`text/html\` |

**Diagnóstico SSH read-only posterior:** ejecución owner-only [#41831886987](https://github.com/pl0n3r/GrindFlow/actions/runs/41831886987), \`workflow_dispatch\`, \`run_attempt=1\`, \`actor=triggering_actor=pl0n3r\`, \`head_sha=98af3115c42ea2aa45018ff78cebb98977c2075c\`. Preflight exact-main, checkout y validación \`bash -n\` pasaron. Paso SSH \`Inspect Hostinger without production writes\` falló a 12:44:40Z con clasificación exacta **\`REMOTE_EXIT_1\`**, **no \`CHECKOUT_DRIFT\`**. Eso significa que la salida remota capturada **no era exactamente** el único marcador permitido \`S4_DIAG_CHECKOUT=DRIFT\`; NO permite concluir si fue \`cd\`, \`bash\`, salida adicional en stdout u otro exit remoto. \`stderr\` y stdout no fueron publicados. No repetir el mismo diagnóstico sin cambio dirigido.

**Estado de aceptación:** AC-01 NO CUMPLIDO (7/7 404 HTML), AC-02 NO CUMPLIDO (sin causa remota ni reparación), AC-03 documentado \`release_sha=null\` sin marker validado, AC-04 sujeto al HEAD documental final. Conservar Reports fuera del alcance de #348 y DSR fail-closed de #457. No se efectuó SSH local directo, no hubo escritura productiva, modificación de caché, migraciones, datos reales ni exposición de secretos. PR #907 sigue DRAFT y NO MERGE.

## Reconciliación tras diagnóstico SSH #928 / PR #929 — 4-nov-2026

<!-- GF903-PR929-MERGED-MAIN20F-DEPLOY-OLD-131407 -->

- El PR auxiliar #929 (https://github.com/pl0n3r/GrindFlow/pull/929) se fusionó por squash en main@20f158826b1b532524beb3e465bada4eb5ce1b3e; #928 quedó CLOSED. El workflow S4 Router Diagnostics conserva owner-only/exact-main/SSH estricto y categoriza exit 1 por salida vacía, primer marcador de checkout, o salida desconocida. No ejecuta modificaciones productivas.
- CI exact-HEAD, SonarCloud, CodeQL, CodeRabbit FINAL y última Factory Policy verde sobre HEAD auxiliar 85bf8f088be505acdeb772ca75c7750ddde016f1; CodeRabbit clasificó dos hallazgos Minor sin high/critical, que se aceptaron expresamente como deuda pre-live en los hilos resueltos y en la issue #903. No hubo revisión extra.
- Una lectura pública a 2026-11-04T13:14:07Z indicó /health commit=98af3115c42ea2aa45018ff78cebb98977c2075c y exact=true mientras GitHub main avanzó a 20f15882…; /s4/health continúa release_sha=null. Esta observación **NO** corresponde al nuevo main, por lo cual no se ejecuta un nuevo workflow SSH hasta confirmar identidad exacta de producción.
- Última prueba exhaustiva de rutas aún es 2026-11-04T12:44:02Z: 7/7 HTTP 404 HTML sobre el main anterior. No atribuir el resultado al commit nuevo ni declarar el incidente reparado; AC-01 y AC-02 siguen incumplidos.
- Mantener Reports (#348) fuera del router mientras el producto lo define, y DSR (#457) fail-closed hasta revisión jurídica. PR #907 permanece DRAFT, sin revisión CodeRabbit FINAL ni merge hasta causa confirmada, backup/rollback y validación de producción.

## Inspección OWNER-only confirma etapa del fallo S4 — 4-nov-2026, 13:16–13:17 UTC

<!-- GF903-SSH-41836592312-HELPER-STARTED-131701-20261104 -->

- Producción /health (GET público, 13:15:36Z) reportó `commit=20f158826b1b532524beb3e465bada4eb5ce1b3e`, `exact=true`; `/s4/health` siguió con `release_sha=null`. [S4 Runtime Prepare #41836292219](https://github.com/pl0n3r/GrindFlow/actions/runs/41836292219) había completado success sobre ese main; esto no prueba aceptación de rutas.
- [S4 Router Diagnostics #41836592312](https://github.com/pl0n3r/GrindFlow/actions/runs/41836592312), `workflow_dispatch` OWNER=pl0n3r, `run_attempt=1`, SHA exacto `20f158826b1b532524beb3e465bada4eb5ce1b3e`: preflight, checkout y `bash -n` success; SSH read-only failure a 13:16:33Z, categoría fija `HELPER_STARTED_EXIT_1`. Se alcanzó la primera línea `S4_DIAG_CHECKOUT=MATCH` del helper, pero una operación posterior devolvió exit 1. No se conoce el último paso: **no atribuir la causa a archivos, vendor, caché o despliegue sin marcador adicional autenticado**.
- GET públicos a 2026-11-04T13:17:01Z, `Cache-Control: no-cache`, sin cookies/credenciales ni redirecciones:

| Ruta GET | HTTP | MIME |
| --- | ---: | --- |
| `/health` | 200 | application/json |
| `/s4/health` | 200 | application/json |
| `/s4/_bridge-readiness` | 200 | application/json |
| `/s4/api/admin/library/collections` | **404** | text/html |
| `/s4/api/admin/library/permissions` | **404** | text/html |
| `/s4/api/admin/plans` | **404** | text/html |
| `/s4/api/admin/rules/automation` | **404** | text/html |
| `/s4/api/admin/reports/cross-module` | **404** | text/html |
| `/s4/api/admin/privacy/data-subject-requests/csrf` | **404** | text/html |
| `/s4/privacidad-y-mis-derechos` | **404** | text/html |

- Resultado: AC-01 **NO** (7/7 404), AC-02 **NO** (falta causa remota y reparación verificable), AC-03 documenta `release_sha=null` sin prueba del marker remoto; AC-04 requiere CI terminal del HEAD documental final. Workflow de solo lectura no ejecutó producción, cachés, SQL, migraciones ni obtuvo datos reales.
- Cambiar el enfoque de diagnosis: instrumentar de manera puramente read-only la etapa precisa donde se interrumpe el helper; nunca repetir `#41836592312` sin código observacional nuevo. Mantener PR #907 en DRAFT, con reserva V3 y lock existentes.


## Localización del fallo en caché S4 — 4-nov-2026 13:41–13:43 UTC

<!-- GF903-41840557961-PREFIX12-MAIN4BCDF-20261104 -->

- [PR #931](https://github.com/pl0n3r/GrindFlow/pull/931) fusionado por squash; [#930](https://github.com/pl0n3r/GrindFlow/issues/930) cerrado. GitHub `main@4bcdf37f2d2e11e49fc68f6b306c6f8ef34b7191`, comprobado también en producción GET `/health` a 13:41:50 UTC con `exact=true`, `version=0.1.225`. GET `/s4/health` continúa `release_sha=null`.
- [S4 Router Diagnostics #41840557961](https://github.com/pl0n3r/GrindFlow/actions/runs/41840557961), owner-only `pl0n3r`, dispatch exact-main, `run_attempt=1`. Preflight, checkout y `bash -n` exitosos; inspección SSH read-only falló a 13:42:37 UTC con categoría **`HELPER_VALID_PREFIX_12_OF_18`**. Esto confirma los doce primeros marcadores completos y correctamente ordenados (incluye `S4_DIAG_CACHE`), sin revelar valores reales; el fallo ocurre antes del campo 13, `S4_DIAG_CACHE_ROUTE_COLLECTIONS`, durante la enumeración/inspección de rutas compiladas.
- La hipótesis de compatibilidad Bash de arreglos vacíos **no está confirmada**: en prueba sintética de Bash 3.2 con `set -euo pipefail`, el contador vacío y rama corta de grep finalizaron sin error. No se concluye falta de archivos cacheados, causa Composer o configuración de hPanel.
- El nuevo resultado no demuestra reparación: último sondeo público integral 13:17 UTC sobre `main@20f15882…` seguía 7/7 HTTP 404 text/html. Sobre `4bcdf37f…` aún no se ha vuelto a medir esa matriz. AC-01/AC-02 **NO CUMPLIDOS** y #907 permanece DRAFT. No se han realizado modificaciones sobre Hostinger, SQL, caché, secretos, migraciones ni datos reales.
- Próximo paso técnico admisible: endurecer el barrido del caché del helper de solo lectura con una salida fallback categórica controlada, probarla offline y solo entonces reejecutar una inspección owner-only para diagnosticar el router. Reports (#348) y DSR (#457) siguen condicionados por producto y revisión legal.

## Reconciliación exact-main y continuación del blocker #933 — 4-nov-2026

<!-- GF903-BLOCKER933-V3-MAIN3F5-RECONCILE-20261104 -->

- `main@3f5d77559237e898591c43fb3030fcbfb427aa78` contiene exclusivamente una prueba sintética de seguridad no relacionada con las rutas S4 (`tests/test_gf346_sensitive_logs_contract.py`), integrada desde PR #932. La rama documental del incidente #903 se reconcilia como merge sin reescribir historia ni invadir claims ajenos.
- El último diagnóstico Owner-only read-only permanece [run #41840557961](https://github.com/pl0n3r/GrindFlow/actions/runs/41840557961) sobre main anterior 4bcdf37f: `HELPER_VALID_PREFIX_12_OF_18`, error antes del campo 13 `CACHE_ROUTE_COLLECTIONS`. No representa reparación, ni permite inferir autoload/cache/hosting. Última matriz pública en este historial: 7/7 GET HTTP 404, sin resultado posterior de reparación.
- [Issue #933](https://github.com/pl0n3r/GrindFlow/issues/933) materializa el endurecimiento exclusivamente offline del helper y los tests. La reserva V3 propia del hijo `trabajo/issue-933` fue comprobada en GitHub con claims `scripts/s4-router-diagnostics.sh` y `tests/test_s4_router_diagnostics.py`, disjuntos de `docs/reconciliacion/s4-rutas-produccion.md` reclamado por la reserva V3 de #903. El contrato AC-01..04 fue corregido para el parser de Factory; no se adelanta ni declara su fusión.
- AC-01/AC-02 **NO CUMPLIDOS**: PR #907 permanece **DRAFT**. No hubo SSH directo, escritura a Hostinger, migraciones, caché, SQL, datos reales, secretos ni go-live. Reports (#348) y DSR (#457) siguen sujetos a los gates de producto/privacidad.


## Reconciliación tras cierre del blocker de caché #933 — 4-nov-2026

<!-- GF903-PR934-MERGED-671BF8-20261104 -->

- [PR #934](https://github.com/pl0n3r/GrindFlow/pull/934) **squash merged** en GitHub `main@671bf8f3828f75a631243abb5964ec1a5625087f`; [issue #933](https://github.com/pl0n3r/GrindFlow/issues/933) está cerrado. El helper `scripts/s4-router-diagnostics.sh` mantiene solo lectura y ahora emite los cinco estados de caché `UNKNOWN` si el escaneo falla o la salida resulta parcial o inesperada.
- Evidencia CI exact-HEAD previa a merge: `trabajo/issue-933@937c06e02c9a4413b3b5bd989be7721474c90736` pasó `validate`, `Factory CI reusable / Validar`, SonarCloud, CodeQL y Factory Policy. CodeRabbit recibió **una sola revisión FINAL** y terminó `COMMENTED` en el HEAD aprobado, sin hallazgos altos/críticos.
- **Deuda Minor pre-live:** CodeRabbit observó que un `find -print0` simulado puede entregar una última ruta **sin byte NUL**, que el escáner podría aceptar como válida. Antes de fase `live`, validar explícitamente el NUL final y añadir fixture `find_valid_path_without_final_nul` para asegurar cinco `UNKNOWN`, sin volver a solicitar CodeRabbit para el PR ya revisado. No se declara corregida esa deuda, ni se otorgan permisos para operar el host.
- El último sondeo público GET de las siete rutas ocurrió 2026-11-04T14:09:26Z, con `/health commit=3f5d7755… exact=true`, y reportó **7/7 HTTP 404 text/html** (evidencia y tabla en el comentario del issue #903, 2026-11-04). Es un SHA previo: no atribuir ese resultado a `671bf8f3…` sin un nuevo sondeo público exacto.
- PR #907 permanece **DRAFT**. AC-01/AC-02 no cumplidos. La próxima lectura owner-only del workflow requiere verificar primero que producción `/health` reporta el SHA actual de main y que no exista run duplicado; no ejecutar cambios de caché, datos, SSH escrituras ni Composer sin gates D-059.


## Evidencia HTTP exact-main tras PR #934 — 4-nov-2026 14:20–14:22 UTC

<!-- GF903-HTTP-20261104-142155-671BF8 -->

- GitHub `main@671bf8f3828f75a631243abb5964ec1a5625087f` (PR #934 fusionado) coincide con `GET https://www.grindflow.com.co/health` a **2026-11-04T14:20:25Z**: `commit=671bf8f3828f75a631243abb5964ec1a5625087f`, `exact=true`, `version=0.1.225`. `GET /s4/health` devuelve `release_sha=null`, `version=0.1.225`.
- Sonda HTTP pública (sin autenticación, cookies, redirecciones, datos personales ni escrituras) a **2026-11-04T14:21:55Z** con `Cache-Control: no-cache`:

| Ruta | HTTP | MIME |
| --- | ---: | --- |
| `/health` | 200 | application/json |
| `/s4/health` | 200 | application/json |
| `/s4/_bridge-readiness` | 200 | application/json |
| `/s4/api/admin/library/collections` | **404** | text/html |
| `/s4/api/admin/library/permissions` | **404** | text/html |
| `/s4/api/admin/plans` | **404** | text/html |
| `/s4/api/admin/rules/automation` | **404** | text/html |
| `/s4/api/admin/reports/cross-module` | **404** | text/html |
| `/s4/api/admin/privacy/data-subject-requests/csrf` | **404** | text/html |
| `/s4/privacidad-y-mis-derechos` | **404** | text/html |

- **AC-01 no cumplido:** siete rutas siguen 404 aun después de integrar #934; #934 solo endurece el diagnóstico read-only, no modifica la configuración del router. **AC-02 no cumplido:** no hay causa productiva confirmada ni reparación efectiva. El SHA coincide pero `release_sha` de Symfony permanece nulo (AC-03 pendiente de reconciliación definitiva).
- Se verificó `S4 Runtime Prepare` `success` sobre el nuevo SHA (run #41846325392); esta conclusión por sí sola no acredita rutas productivas reparadas ni el estado terminal D-043.
- El intento de iniciar la inspección OWNER-only del workflow `s4-router-diagnostics.yml` sobre `671bf8f3828f75a631243abb5964ec1a5625087f` **fue rechazado por los controles del entorno antes de la ejecución**; no se generó un run nuevo desde esa acción. No se reintentó por otra vía y no se hicieron cambios de host/caché/Composer/BD.
- Persisten decisiones de producto y privacidad: Reports (#348) y DSR (#457) tienen comportamiento 404 previsto en ciertas condiciones. Se exige decisión del propietario para AC-01 antes de alterar esa semántica, más autorización/backup/rollback para cualquier reparación productiva según D-059. Mantener PR #907 DRAFT hasta evidencia auténtica.


## Confirmación HTTP externa posterior — 4-nov-2026, 14:35:05 UTC
<!-- GF903-HTTP-20261104-143505-671BF8 -->

- **Sondeo GET público, solo lectura**, desde dispositivo conectado, con `Cache-Control: no-cache`, sin cookie, sesión, credencial, redirección manual ni envío de datos personales. Se recogieron únicamente estado HTTP, MIME y campos públicos acotados de `/health` / `/s4/health`; no se ejecutó SSH ni workflow de diagnóstico.
- GitHub `main@671bf8f3828f75a631243abb5964ec1a5625087f` coincide con `GET /health` (**200 application/json**, `exact=true`). `GET /s4/health` = **200 application/json**, `release_sha=null`; `GET /s4/_bridge-readiness` = **200 application/json**.

| GET bajo `/s4` | HTTP | MIME |
| --- | ---: | --- |
| `/api/admin/library/collections` | **404** | text/html |
| `/api/admin/library/permissions` | **404** | text/html |
| `/api/admin/plans` | **404** | text/html |
| `/api/admin/rules/automation` | **404** | text/html |
| `/api/admin/reports/cross-module` | **404** | text/html |
| `/api/admin/privacy/data-subject-requests/csrf` | **404** | text/html |
| `/privacidad-y-mis-derechos` | **404** | text/html |

- **Veredicto QA/SRE:** incidente sin reparar: AC-01 7/7 rutas aún 404 HTML; AC-02 sin causa remota autenticada ni comando correctivo probado; AC-03 `release_sha` nulo comprobado, no inferir release Symfony por identidad Laravel. Esto **no valida producción** ni sustituye los gates operativos.
- **Autoridad pendiente:** inspección owner-only productiva específica y reconciliación de aceptación para Reports #348 / DSR #457 (opciones A/B en el [comentario #6633307984](https://github.com/pl0n3r/GrindFlow/issues/903#issuecomment-6633307984)). Ninguna modificación Hostinger, rutas de producto o privacidad se autoriza por este documento. Backups/rollback verificados siguen siendo necesarios antes de escribir, incluso si `S4 Runtime Prepare` figura verde.
- **Concurrencia:** conservar PR #907 DRAFT y reserva V3 `cbb53f02-a68b-4d1d-b118-4dfc4efea06d`; el lock `coordinacion/lock-issue-903` no debe forzarse/borrarse ni recuperarse mientras no se satisfaga el contrato del recuperador. CodeRabbit se solicita solo una vez sobre HEAD final con CI verde y aceptación auténtica.


## Evidencia de QA/SRE sobre main exacto y CI — 4-nov-2026, 16:39 UTC

<!-- GF903-OFFLINE-EXACTMAIN-1639-20261104 -->

- **Origen verificable:** sondeo GET público anónimo del 4-nov-2026 a las **16:39:12 UTC**, ya registrado en [issue #903, comentario de reconciliación](https://github.com/pl0n3r/GrindFlow/issues/903#issuecomment-6633437029). Solicitudes de solo lectura con `Cache-Control: no-cache`; sin login, cookies, redirecciones, secretos ni acciones de host.
- **Identidad productiva comprobada:** GitHub `main@e74497a7c4112c58516b6b3c8886870cade6c243` coincide con `GET /health` HTTP **200 JSON**, `commit=e74497a7c4112c58516b6b3c8886870cade6c243`, `exact=true`, `version=0.1.225`. Esto demuestra identidad del runtime Laravel, **no** del release Symfony.
- `GET /s4/health` = **200 JSON**, `release_sha=null`, `version=0.1.225`; `GET /s4/_bridge-readiness` = **200 JSON**. El estado de Symfony sigue sin identidad de release verificable.

| GET público en `https://www.grindflow.com.co` | HTTP | MIME |
| --- | ---: | --- |
| `/s4/api/admin/library/collections` | **404** | text/html |
| `/s4/api/admin/library/permissions` | **404** | text/html |
| `/s4/api/admin/plans` | **404** | text/html |
| `/s4/api/admin/rules/automation` | **404** | text/html |
| `/s4/api/admin/reports/cross-module` | **404** | text/html |
| `/s4/api/admin/privacy/data-subject-requests/csrf` | **404** | text/html |
| `/s4/privacidad-y-mis-derechos` | **404** | text/html |

- **Checks del PR #907 anteriores a esta actualización documental:** `trabajo/issue-903@d33d77da3abd84a2ffc8ef4be5b3b6a0995de207` (`main` reconciliado, `behind=0`): `GrindFlow CI`, `CI Factory v1`, `Privacidad`, `Aceptación ejecutable` y `Etiquetas` completaron `success`. `Coordinación` [run #41866765437](https://github.com/pl0n3r/GrindFlow/actions/runs/41866765437) terminó `failure` por `Lock V3 ocupado para Issue #903; mutación denegada.`; `Política Factory v1` [run #41866765460](https://github.com/pl0n3r/GrindFlow/actions/runs/41866765460), reintentada una vez con checks terminales, terminó `failure` por `Check no verde: pr / Sincronizar PR=failure.`. No atribuir checks anteriores al HEAD generado por esta actualización: exigir evaluación exact-HEAD nueva.
- **Criterios reales:** AC-01 = **NO** (7/7 rutas 404), AC-02 = **NO** (causa en host sin confirmar y ningún workflow reparador autorizado ejecutado en este frente); AC-03 = `release_sha=null` explicado, no dar identidad Symfony como verificada; AC-04 depende de `validate` exact-HEAD y política de merge aplicable. El check automático `Aceptación ejecutable=success` no reemplaza la aceptación humana/productiva.
- **Puertas:** PR #907 permanece DRAFT, sin CodeRabbit FINAL ni squash. El workflow `S4 Router Diagnostics` es owner-only sobre `main` y requiere autorización operativa específica; no se hizo SSH ni se tocó Hostinger. Antes de cualquier operación de escritura exige autorización diferenciada, backup/restore y rollback verificados (D-059). Reports #348 requiere decisión de producto, DSR #457 dictamen jurídico verificable; no cambiar semántica de estas rutas sin esas autoridades. El lock de coordinación no se recupera con lease V3 activa; no forzar ni ocultar la colisión.


## Contraste de rutas legacy frente a S4 recientes — medición pública 4-nov-2026 18:10:41 UTC

<!-- GF903-RECON-NEW-OLD-ROUTE-1810-20261104 -->

**Objeto:** discriminar un 404 universal de Symfony de un posible router/caché/checkout selectivamente desactualizado. GET anónimos sin cookies, credenciales ni seguimiento de redirecciones, desde dispositivo autorizado hacia `https://www.grindflow.com.co`, con `Cache-Control: no-cache`. No se leyó ni modificó el host mediante SSH. Evidencia de sondeo y clasificación publicada en [#903, comentario operativo](https://github.com/pl0n3r/GrindFlow/issues/903#issuecomment-6633437029).

| GET relativo a `/s4` | HTTP | Tipo observado | Declaración en `main@e74497a7` |
| --- | ---: | --- | --- |
| `/` | 200 | HTML | `HomeController` |
| `/login` | 200 | HTML | `LoginController` |
| `/preview` | 503 | HTML | `PreviewController`; no demuestra éxito funcional |
| `/api/admin/context` | 500 | HTML | `AdminContextController`; error de backend, no concluir causa |
| `/api/admin/vault` | 500 | HTML | `VaultController`; error de backend, no concluir causa |
| `/api/admin/library/collections` | 404 | HTML | `LibraryCollectionsController`: sí declarada |
| `/api/admin/library/permissions` | 404 | HTML | `LibraryHistoryController`: sí declarada |
| `/api/admin/plans` | 404 | HTML | `PlanLimitsAdminController`: sí declarada |
| `/api/admin/rules/automation` | 404 | HTML | `PublishingAutomationController`: sí declarada |
| `/api/admin/reports/cross-module` | 404 | HTML | **No importada** por `symfony/config/routes.yaml`; decisión de producto #348 |
| `/api/admin/privacy/data-subject-requests/csrf` | 404 | HTML | `DataSubjectRequestsController`: sí declarada; con feature deshabilitada retorna **404 JSON** intencional |
| `/privacidad-y-mis-derechos` | 404 | HTML | `PrivacyRightsController`: sí declarada; vista 200 prevista |

**Control positivo:** `/s4/_bridge-readiness` devuelve HTTP 200 JSON, `data.state=ready_for_web_probe`; esto confirma acceso al bridge, no identidad de `symfony/vendor`, caché compilada ni release de controladores.

**Interpretación verificable:** las rutas S4 antiguas pueden resolver o alcanzar el controlador (200/503/500), pero las seis rutas nuevas declaradas en código siguen respondiendo 404 HTML. La hipótesis de diferencia entre checkout publicado, registro de rutas compilado y fuente actual merece una inspección remota owner-only; ninguna de las observaciones permite concluir **causa confirmada** ni ejecutar Composer/caché/deploy como reparación probada. No inferir éxito de endpoints 500/503, ni alterar funcionalidad DSR/Reports para forzar el 200 del AC-01.

**Semáforo del incidente:** AC-01 **NO** (7/7 siguen HTTP 404), AC-02 **NO** (causa de host no comprobada; reparación autorizada no ejecutada), AC-03 `release_sha=null` **explicado, no validado**, AC-04 sujeto a la ejecución canónica `validate` del HEAD definitivo. Requiere autorización owner específica de diagnóstico (lectura), decisión expresa de criterio A/B para Reports/DSR, permiso de escritura independiente con backup/restore y rollback verificables D-059, y resolución del lock V3 por protocolo sin fuerza manual. PR #907 se mantiene **DRAFT**, sin revisión FINAL CodeRabbit ni squash.


## Diagnóstico remoto seguro y verificación HTTP — 4-nov-2026 19:42–19:47 UTC

<!-- GF903-READONLY-RUN41899802049-1942UTC -->

**Ejecución real:** [S4 Router Diagnostics run #41899802049](https://github.com/pl0n3r/GrindFlow/actions/runs/41899802049), `workflow_dispatch` owner-only, `main@e74497a7c4112c58516b6b3c8886870cade6c243`, creado 19:44:09 UTC; `completed/success` al 19:44:19 UTC, primer intento. El job [#126818885383](https://github.com/pl0n3r/GrindFlow/actions/runs/41899802049/job/126818885383) pasó `Fail closed unless the dispatch is exact current-main`, `Validate allowlisted read-only helper`, `Inspect Hostinger without production writes` y `Revalidate exact main after remote read-only inspection`. No se ejecutaron Composer, cache clear/warmup, despliegues ni escritura en Hostinger. Fue un intento causalmente nuevo: el helper que falló en #41840557961 (`HELPER_VALID_PREFIX_12_OF_18`, blob `f3791fa5…`) cambió en `main` al blob `7f03fd19…`.

**Límite de evidencia:** el job exitoso publicaba 18 indicadores categóricos `S4_DIAG_*` únicamente en `GITHUB_STEP_SUMMARY`. Ni la API del check-run (campo `output.summary=null`), ni logs del job ni los artefactos recuperables exponen aquí los valores. **No se atribuye `CHECKOUT=MATCH`, `AUTOLOAD=MATCH`, `CACHE_ROUTE_*=MATCH`, causa confirmada ni reparación** a partir del éxito del job. Antes de cualquier cambio productivo se deben leer los indicadores mediante el resumen autorizado del run.

**Sonda real GET pública** de **19:42:45 UTC**, sin cookies, autenticación, seguimiento de redirecciones ni escritura, `Cache-Control: no-cache`. La comprobación de campos públicos a las **19:46:50 UTC** estableció `/health` = `200 application/json`, `commit=e74497a7c4112c58516b6b3c8886870cade6c243`, `exact=true`, versión `0.1.225`; `/s4/health` = `200 application/json`, `release_sha=null`; `/s4/_bridge-readiness` = `200 application/json`, `data.state=ready_for_web_probe`. Eso demuestra identidad Laravel, no el SHA de Symfony.

| Ruta GET pública | HTTP | MIME |
| --- | ---: | --- |
| `/s4/` | 200 | text/html |
| `/s4/login` | 200 | text/html |
| `/s4/api/admin/library/collections` | **404** | text/html |
| `/s4/api/admin/library/permissions` | **404** | text/html |
| `/s4/api/admin/plans` | **404** | text/html |
| `/s4/api/admin/rules/automation` | **404** | text/html |
| `/s4/api/admin/reports/cross-module` | **404** | text/html |
| `/s4/api/admin/privacy/data-subject-requests/csrf` | **404** | text/html |
| `/s4/privacidad-y-mis-derechos` | **404** | text/html |

**Dictamen de aceptación:** AC-01 = NO, siete rutas 404 HTML; AC-02 = NO, no consta causa productiva confirmada ni corrección aplicada; AC-03 = `release_sha=null` explicado pero identidad Symfony no demostrada; AC-04 requiere `validate` terminal sobre el HEAD definitivo de esta rama. La [evidencia registrada en el Issue #903](https://github.com/pl0n3r/GrindFlow/issues/903#issuecomment-6640450516) conserva los metadatos íntegros. El 404 HTML de DSR no debe confundirse con el 404 JSON deliberado del controlador; Reports no está importada en el router, según #348. Pendiente decisión del dueño sobre contrato AC-01; no habilitar funcionalidad por inferencia.

**Continuación segura:** obtener los 18 valores del resumen del run, identificar raíz real y dejar respaldos/restauración y rollback verificables antes de cualquier escritura autorizada. Conservar reserva V3 autenticada y lock tal cual; no alterar claims, forzar ref, repetir `/tomar` ni usar `/liberar-forzado` para fabricar las precondiciones. PR #907 continúa DRAFT sin CodeRabbit FINAL ni squash hasta aceptación productiva y validación exact-HEAD.


## Evidencia SRE exact-main y contrato de desbloqueo — 2026-11-04 20:24 UTC

<!-- GF903-PROBE-20261104T202406Z -->

**Medición ejecutada realmente:** `2026-11-04T20:24:06Z`, GET públicos anónimos de solo lectura contra `https://www.grindflow.com.co`, `Cache-Control: no-cache`, `--max-redirs 0`, sin cookies ni secretos y sin escribir en el host.

| Endpoint completo (ruta relativa al dominio) | HTTP | MIME |
| --- | ---: | --- |
| `/health` | 200 | `application/json` |
| `/s4/health` | 200 | `application/json` |
| `/s4/_bridge-readiness` | 200 | `application/json` |
| `/s4/` | 200 | `text/html` |
| `/s4/login` | 200 | `text/html` |
| `/s4/api/admin/library/collections` | **404** | `text/html` |
| `/s4/api/admin/library/permissions` | **404** | `text/html` |
| `/s4/api/admin/plans` | **404** | `text/html` |
| `/s4/api/admin/rules/automation` | **404** | `text/html` |
| `/s4/api/admin/reports/cross-module` | **404** | `text/html` |
| `/s4/api/admin/privacy/data-subject-requests/csrf` | **404** | `text/html` |
| `/s4/privacidad-y-mis-derechos` | **404** | `text/html` |

**Consistencia:** el sondeo anterior de 20:18 UTC también reprodujo los siete 404 HTML. La identidad de Laravel previamente verificada en `/health` era `commit=e74497a7c4112c58516b6b3c8886870cade6c243`, `exact=true`, `version=0.1.225`; `/s4/health` seguía con `release_sha=null`. Estos datos no identifican el checkout/caché de Symfony. El [diagnóstico owner-only read-only #41899802049](https://github.com/pl0n3r/GrindFlow/actions/runs/41899802049) completó sus pasos correctamente, pero el output de las 18 categorías `S4_DIAG_*` fue escrito en `GITHUB_STEP_SUMMARY`, no aparece en logs/check-run API ni en artefactos descargables. No inferir su contenido, un fallo de autoload, ni un parche.

**Bloqueador de coordinación comprobado en código, sin acción prohibida:** [Factory #2009](https://github.com/pl0n3r/Factory/issues/2009) está fusionado y cerrado; `factory/main/scripts/coordinar_trabajo.py` contiene `recover_live_owner_v3_orphaned_issue_lock` pero lo define literalmente como **offline-only; deliberately not wired to commands**. La etiqueta distribuida `Factory@v1`, ref `1e809674854ae9e9d3426f32413834709ab0c42d`, no contiene esa rutina. El consumer `GrindFlow/main/.github/workflows/work-coordination.yml` utiliza `coordinacion.yml@v1` y solamente acepta `/recuperar-lock-huerfano <sha40>`, que por diseño rechaza una reserva V3 todavía activa. La ref firmada `coordinacion/lock-issue-903@1a1f22412b4e81cc74bfe52603b61b1147497bb1` y la reserva legítima `cbb53f02-a68b-4d1d-b118-4dfc4efea06d` se preservan. Sin publicación protegida y habilitación serializada de ese nuevo protocolo, no hay comando seguro para quitar este lock sin cerrar el PR ni falsificar autoridad.

**CI terminal exact-HEAD documental anterior `725ead1c723b2f6e2e805e2d2b27578a52b93fb1`:** `GrindFlow CI / validate`, `Factory CI reusable / Validar`, SonarCloud y CodeQL success. El run de Coordinación [#41904105288](https://github.com/pl0n3r/GrindFlow/actions/runs/41904105288) terminó failure (`Lock V3 ocupado para Issue #903; mutación denegada`). El run de Política [#41904105825](https://github.com/pl0n3r/GrindFlow/actions/runs/41904105825) acabó en `Check no verde: pr / Sincronizar PR=failure` (no error de snapshots en ese intento). Tras **este commit documental**, los checks deben evaluarse otra vez en el **nuevo HEAD**; nunca atribuir al HEAD nuevo resultados del anterior.

**Aclaración de aceptación:** Reports #348 permanece fuera del import del router por decisión de producto; DSR #457 alcanza un 404 JSON `feature_unavailable` cuando el controlador deshabilitado es alcanzado, distinto del 404 HTML visto. No habilitar esos módulos para maquillar AC-01. Se mantiene el contrato original: **AC-01 NO, AC-02 NO; AC-03 nulo explicado, no SHA de Symfony confirmado; AC-04 requiere validate exact-HEAD nuevo**. La decisión A/B solicitada al owner y la comprobación de backup/restauración/rollback siguen pendientes. No se ha cambiado ni desplegado producción.

**Secuencia mínima de desbloqueo (sin falsos positivos):** obtener los 18 indicadores sanitizados de diagnóstico, confirmar con ellos la causa y elegir el cambio operativo mínimo; antes de cualquier mutación del host cumplir D-059 y el permiso de reparación necesario; aplicar reparación con reversión verificada, volver a sondear siete rutas con la semántica aprobada; resolver el lock por comando owner-only serializado *publicado y autorizado*; registrar antes/después y causa confirmada; ejecutar `validate`/ `Validar` exact-HEAD y seguridad; pedir **una sola revisión FINAL de CodeRabbit** y fusionar squash solo con los gates esenciales realmente acreditados. Mantener #907 DRAFT y abierta mientras falten.


## Actualización de aceptación y trazabilidad CI — 4-nov-2026 22:19–22:25 UTC

<!-- GF903-HTTP-POLICY-20261104T222542Z -->

**Ejecución reproducible de solo lectura.** Sondeo GET anónimo del 2026-11-04T22:25:42Z, con Cache-Control: no-cache y sin cookies, credenciales ni operaciones de escritura, a https://www.grindflow.com.co. Resultado público:

| Ruta | HTTP | MIME |
| --- | ---: | --- |
| `/health` | 200 | `application/json` |
| `/s4/health` | 200 | `application/json` |
| `/s4/_bridge-readiness` | 200 | `application/json` |
| `/s4/api/admin/library/collections` | **404** | `text/html` |
| `/s4/api/admin/library/permissions` | **404** | `text/html` |
| `/s4/api/admin/plans` | **404** | `text/html` |
| `/s4/api/admin/rules/automation` | **404** | `text/html` |
| `/s4/api/admin/reports/cross-module` | **404** | `text/html` |
| `/s4/api/admin/privacy/data-subject-requests/csrf` | **404** | `text/html` |
| `/s4/privacidad-y-mis-derechos` | **404** | `text/html` |

**Reintento único de Policy con causa diferenciada.** El job original `Factory policy / Validar decisiones y límite de revisión`, [#126842396704](https://github.com/pl0n3r/GrindFlow/actions/runs/41906486071/job/126842396704), falló por `Check-runs sin dos snapshots completos y estables`. Se reejecutó exclusivamente ese job; el intento [#126904500038](https://github.com/pl0n3r/GrindFlow/actions/runs/41906486071/job/126904500038) terminó `failure` tras superar los snapshots y reportó el error concreto `Check no verde: pr / Sincronizar PR=failure.`. Su dependencia es [Coordinación / pr / Sincronizar PR #126842443545](https://github.com/pl0n3r/GrindFlow/actions/runs/41906486099/job/126842443545), denegada por `Lock V3 ocupado para Issue #903; mutación denegada.` No hay base para otro reintento ciego de Policy. [Evidencia completa en issue](https://github.com/pl0n3r/GrindFlow/issues/903#issuecomment-6643612195).

**Reserva y dependencia de release.** El PR #907 antes de esta actualización documental estaba `OPEN/DRAFT` en `6faf759c646cecaa830bf1602f92c2cfe7259bfd`, `behind=0`, con claim exclusivo sobre este archivo y V3 activa `cbb53f02-a68b-4d1d-b118-4dfc4efea06d`. El lock `coordinacion/lock-issue-903@1a1f22412b4e81cc74bfe52603b61b1147497bb1` no se alteró. `Factory/main@67aeb1e4381664860de0cbca2915c0b2a1e2d276` prepara 1.0.49, pero el tag distribuido protegido `Factory@v1@1e809674854ae9e9d3426f32413834709ab0c42d` aún corresponde a 1.0.48. La recuperación same-OWNER CAS V3 requiere publicación protegida exact-SHA y habilitación del consumidor reservado en #935/PR #937; jamás borrar el lock por fuerza ni volver a tomar la reserva activa.

**Aceptación honesta:** AC-01 = NO (7/7 rutas todavía 404 HTML); AC-02 = NO (falta causa confirmada del host y corrección desplegada); AC-03 = `release_sha=null` previamente documentado y Symfony sin identidad de release confirmada; AC-04 = repetir validaciones sobre el **nuevo HEAD documental**. La ruta Reports depende de producto #348 y el 404 previsto para DSR deshabilitada es JSON, no el 404 HTML actual: exigir decisión explícita del dueño antes de variar el contrato. La ejecución read-only del workflow S4 #41899802049 no publica sus 18 categorías de diagnóstico en las APIs accesibles; no inventar su contenido.

**No-go:** este commit solo incorpora pruebas obtenidas y causa de CI; no modifica Hostinger ni valida producción. Antes de cualquier escritura S4 siguen obligatorios autorización operativa diferenciada, respaldo/restauración y rollback acreditados (D-059); no pasar el PR a Ready, no solicitar CodeRabbit FINAL y no fusionar hasta aceptación real, CI exact-HEAD y gates auténticos D-069/D-043.


## Última observación HTTP pública — 2026-11-04T22:56:31Z

<!-- GF903-S4-HTTP-225631-20261104 -->

Nuevo sondeo **solo lectura** (12 GET HTTPS anónimos, sin credenciales, cookies, mutaciones ni seguir redirecciones) contra `https://www.grindflow.com.co`, desde el dispositivo de diagnóstico autorizado. No autentica el estado interno de Hostinger, ni valida el route map con `debug:router`.

| Ruta | HTTP | MIME |
| --- | ---: | --- |
| `/health` | 200 | `application/json` |
| `/s4/health` | 200 | `application/json` |
| `/s4/_bridge-readiness` | 200 | `application/json` |
| `/s4/` | 200 | `text/html` |
| `/s4/login` | 200 | `text/html` |
| `/s4/api/admin/library/collections` | **404** | `text/html` |
| `/s4/api/admin/library/permissions` | **404** | `text/html` |
| `/s4/api/admin/plans` | **404** | `text/html` |
| `/s4/api/admin/rules/automation` | **404** | `text/html` |
| `/s4/api/admin/reports/cross-module` | **404** | `text/html` |
| `/s4/api/admin/privacy/data-subject-requests/csrf` | **404** | `text/html` |
| `/s4/privacidad-y-mis-derechos` | **404** | `text/html` |

La respuesta pública `/health` anunció `commit=e74497a7c4112c58516b6b3c8886870cade6c243`, `version=0.1.225` y `exact=true`; `/s4/health` anunció `version=0.1.225` y `release_sha=null`. El `200` de health y bridge no prueba rutas registradas. El `404 text/html` en DSR tampoco equivale al `404 JSON feature_unavailable` intencional del controlador si estuviera montado.

**Resultado:** AC-01=NO; AC-02=NO; AC-03 sigue sin identidad de release Symfony; AC-04 requiere gates exact-HEAD después de cualquier commit nuevo. La causa de los seis 404 candidatos a runtime sigue sin diagnóstico autenticado. Reports #348 y DSR #457 requieren mantener sus decisiones de producto/privacidad.

**Dependencia protegida comprobada:** [Factory #2016](https://github.com/pl0n3r/Factory/issues/2016) está `OPEN` sin comentarios de decisión a la última consulta; solicita `/decidir A` o `/decidir B` exclusivamente al OWNER para `Factory/main@67aeb1e4381664860de0cbca2915c0b2a1e2d276` (1.0.49). Su ventana registrada vence `2026-11-04T23:43:11Z`. `Factory@v1` sigue en el SHA publicado de 1.0.48; no usar la puerta abierta como aprobación. Sin release protegido y sin la integración gobernada de #935/PR #937, no operar CAS V3 real sobre el lock de #903.

No se han hecho escrituras en producción, movimientos de `v1`, cambios de lock/lease ni solicitud FINAL de CodeRabbit.


## Contraste posterior del prepare exact-main (5-nov-2026, 01:45 UTC)

<!-- GF903-POSTPREPARE-EVIDENCE-20261105T014500Z -->

La [corrida S4 Runtime Prepare #41867363828](https://github.com/pl0n3r/GrindFlow/actions/runs/41867363828) se disparó a partir de [GrindFlow Deploy Observer #41866216795](https://github.com/pl0n3r/GrindFlow/actions/runs/41866216795) del `push` de `main@e74497a7c4112c58516b6b3c8886870cade6c243`. En la corrida `S4 Runtime Prepare` el job `exact-s4-runtime-prepare` terminó en `success`, con éxito comprobado para los pasos de SHA exacto, obtención del helper desde ese SHA, **preparación de dependencias Symfony en el checkout hPanel**, segunda verificación de SHA y prueba del bridge. No se deduce de ello que el mapa de rutas Symfony se haya reconstruido ni que las rutas estén operativas.

Un sondeo HTTPS independiente, anónimo y **solo lectura**, posterior a esa preparación, registró el siguiente resultado a las **2026-11-05T01:45:00Z** contra `https://www.grindflow.com.co`:

| Ruta | HTTP observado | Tipo de respuesta |
| --- | ---: | --- |
| `/health` | 200 | JSON |
| `/s4/health` | 200 | JSON |
| `/s4/_bridge-readiness` | 200 | JSON |
| `/s4/api/admin/library/collections` | **404** | HTML |
| `/s4/api/admin/library/permissions` | **404** | HTML |
| `/s4/api/admin/plans` | **404** | HTML |
| `/s4/api/admin/rules/automation` | **404** | HTML |
| `/s4/api/admin/reports/cross-module` | **404** | HTML |
| `/s4/api/admin/privacy/data-subject-requests/csrf` | **404** | HTML |
| `/s4/privacidad-y-mis-derechos` | **404** | HTML |

**Conclusión delimitada:** ejecutar de nuevo Composer mediante el mismo `S4 Runtime Prepare`, sin evidencia nueva, no tiene una justificación causal: **ya se ejecutó correctamente sobre el SHA observado, pero las siete rutas persistieron en 404**. El resultado no distingue por sí solo entre caché obsoleta, falta de registro de controladores, configuración de imports u otra causa. Una corrida `success` no equivale a aceptación productiva.

**Siguiente diagnóstico seguro:** inspeccionar de forma autenticada y **solo lectura** el mapa de `debug:router`, la importación de rutas por atributos, el autoload real, la caché Symfony efectiva y la identidad del checkout remoto. Si se requiere reconstruir caché, hacerlo únicamente con destino y SHA verificables, respaldo previo y procedimiento de reversión autorizado. Respetar los límites funcionales de Reports #348 y DSR #457: no habilitar prestaciones retiradas por producto o privacidad solo para producir códigos HTTP diferentes.

**Gobernanza:** evidencia asimismo registrada en [comentario #6647378363 de #903](https://github.com/pl0n3r/GrindFlow/issues/903#issuecomment-6647378363). Factory #2016 mantiene pendiente su decisión OWNER para publicar 1.0.49 y activar el caller CAS V3 de #935/PR #937; por tanto no se ha recuperado el lock de #903. El presente cambio es **documental y offline**: no se tocó producción, caché, secretos, locks, `Factory@v1`, SQL ni datos. **AC-01 y AC-02 siguen incumplidos**, y CodeRabbit FINAL permanece sin solicitar.

## Reparación productiva comunicada por el dueño · 5-nov-2026
<!-- GF903-REPAIR-CACHE-OWNER-20261105 -->

**Fuente:** [comentario del propietario #6651765531](https://github.com/pl0n3r/GrindFlow/issues/903#issuecomment-6651765531). Esta sección reproduce su evidencia. Este agente no ejecutó SSH, Composer, cache:clear, deploy ni otras escrituras productivas.

**Causa acreditada para las rutas recuperadas:** el dueño verificó que el checkout remoto era `main@e74497a7c4112c58516b6b3c8886870cade6c243`, pero `symfony/var/cache/prod` y `var/cache/production` conservaban fecha 6-oct-2026, anterior a los controladores agregados el 11-oct. `S4 Runtime Prepare` había finalizado correctamente y no reconstruyó esa caché. El operador informó respaldo `var/cache/prod.bak-20261105` y ejecución de `APP_ENV=prod php bin/console cache:clear --env=prod --no-debug`, sin alterar `.env`, datos ni migraciones. El backup declarado no demuestra por sí solo un restore ensayado ni un recibo D-059 terminal.

| Ruta S4 sin sesión | Antes | Después comunicado por el propietario |
| --- | --- | --- |
| `/s4/api/admin/plans` | 404 HTML | **401 JSON** |
| `/s4/api/admin/library/collections` | 404 HTML | **401 JSON** |
| `/s4/api/admin/library/permissions` | 404 HTML | **401 JSON** |
| `/s4/api/admin/rules/automation` | 404 HTML | **401 JSON** |
| `/s4/privacidad-y-mis-derechos` | 404 HTML | **200 HTML** |
| `/s4/api/admin/reports/cross-module` | 404 HTML | **404 HTML**, ruta no importada |
| `/s4/api/admin/privacy/data-subject-requests/csrf` | 404 HTML | **404 JSON** `feature_unavailable`, deshabilitación intencional |

**AC-01 = NO (5/7 resueltas según el propietario):** la ruta Reports no se importa porque `symfony/config/routes.yaml` solo incluye `src/Http/Controller/`; `CrossModuleReportController` está en `src/Http/Reports/`. Bloqueador técnico [#939](https://github.com/pl0n3r/GrindFlow/issues/939); [PR #950](https://github.com/pl0n3r/GrindFlow/pull/950) **fusionado por squash** en `main@6f3034904d6e2218c354358b40e32d8e79a609a7` el 5-nov-2026, tras `validate`, `symfony-preview`, `Factory CI reusable / Validar`, SonarCloud, CodeQL y una única revisión CodeRabbit FINAL exact-HEAD; la fusión no prueba despliegue ni HTTP productivo posterior. La ruta DSR sí alcanza ahora un controlador que responde `404 JSON feature_unavailable` por su protección legal/test-only [#457](https://github.com/pl0n3r/GrindFlow/issues/457). No modificar DSR solo para forzar un 401/302/200.

**AC-02 = PARCIAL:** caché obsoleta confirmada por el operador para cinco rutas; import faltante de Reports confirmado en código. Falta evidencia posterior al despliegue de #939 y decisión de aceptación sobre DSR. **AC-03 = PENDIENTE:** `/s4/health.release_sha=null` continúa sin identidad certificada del release Symfony; `.release-sha` ausente es solo hipótesis. **AC-04** debe validarse sobre el HEAD final de #907.

**Gobernanza:** #903 y su PR #907 siguen abiertos/DRAFT, reserva V3 `cbb53f02-a68b-4d1d-b118-4dfc4efea06d`; persiste la colisión del lock V3 de Coordinación. No borrar/forzar refs. La opción A registrada por el dueño en Factory #2016 no mueve por sí sola el tag protegido `Factory@v1`. Cualquier nueva reparación Hostinger, cambio semántico DSR o movimiento de release requiere su autorización específica. No pedir CodeRabbit FINAL ni fusionar antes de aceptación y gates auténticos.
