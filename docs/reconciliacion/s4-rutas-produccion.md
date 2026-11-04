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
- **Acceso y límites:** la sesión solo tiene GitHub de lectura/escritura, **no un canal autenticado aprobado de diagnóstico SSH/hPanel** para #903 ni constancia de backup/restore anterior a intervención. Las consultas públicas desde este entorno no resuelven DNS de `www.grindflow.com.co` y el lector web no obtiene la URL; no se efectuó una lectura actual de las rutas. Un workflow `success` o CI `validate` verde no convierte esa ausencia en `VALIDATED_IN_PRODUCTION`. No repetir el prepare productivo ni alterar Symfony/Hostinger/caché sin permiso/backup; no abrir otra hoja duplicada para #348.
- **Próxima acción ejecutable:** obtener autorización específica para `debug:router` y lectura sanitaria de SHA, controladores y caché en el checkout actual; separar seis 404 de la exclusión intencional de Reports. Tras un cambio reversible expresamente aprobado, repetir GET y anotar hora, SHA, los seis HTTP y su causa; tramitar AC-01 conforme al contrato renovado **con autoridad**, luego pasar CI exact-HEAD y una sola revisión CodeRabbit FINAL antes de quitar DRAFT.

## Reentrada, controles y rollback

1. Antes de una consulta autenticada por SSH/hPanel o cualquier escritura, obtener autorización específica y evidencia de backup/restore y reversión según [AGENTS.md](../../AGENTS.md), [docs/DEPLOY-HOSTINGER.md](../DEPLOY-HOSTINGER.md), D-043/D-059 y el alcance explícito de #903. **No se ha otorgado ni usado ese acceso en esta conciliación.**
2. Si el diagnóstico demuestra caché Symfony obsoleta, preparar un procedimiento controlado para reconstruir rutas/contenedor, preservando snapshot para rollback y sin migraciones, cambios de `.env` ni datos reales; tramitar cualquier ampliación de claims y gate antes de cambiar scripts/workflows.
3. Tras una reparación auténtica, tomar otro sondeo GET de las siete rutas y documentar los estados HTTP antes/después, el SHA exacto, comando/workflow, recibo del backup y resultado del rollback si fuera necesario. El objetivo es 401/302/200 en las siete; un 404 residual es NO GO.
4. Pasar `GrindFlow CI / validate` sobre HEAD exacto y la revisión final CodeRabbit **una sola vez**, únicamente cuando el cambio esté completo y los gates aplicables estén en verde.

**Estado de aceptación actual:** AC-01 pendiente (7/7 rutas 404); AC-02 pendiente (hipótesis sin causa raíz autenticada y sin reparación); AC-03 explicación de `release_sha=null` documentada, sin declarar marca de release real; AC-04 condicionado a `validate` terminal en el HEAD exacto del PR (sin sustituir AC-01 ni AC-02). Mantener #903 abierto y cualquier PR de preparación en DRAFT hasta superar los gates.