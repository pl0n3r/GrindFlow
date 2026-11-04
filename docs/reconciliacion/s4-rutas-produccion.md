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
