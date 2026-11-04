# Auditoría de disponibilidad funcional backend ↔ interfaz — GrindFlow #918

**Corte:** 2026-11-04, `main@7a3c3d95e1a1dc3fbc6e5d638a352f1919aee2a7` · versión humana `0.1.225`. **Solicitante:** propietario; **roles:** Producto, Ingeniería, QA y SRE. Este documento contrasta las fuentes del árbol y auditorías anteriores; **no constituye una certificación funcional de producción**.

## Alcance, método y reglas de evidencia

1. Fuentes de requisitos: [REQUIREMENTS](../REQUIREMENTS.md), [SPEC](../GRINDFLOW-SPEC.md), [transición](../STACK-TRANSITION-SYMFONY.md), [matriz de cobertura](../matriz-cobertura.md), [paridad ejecutable](../PARITY-MATRIX-EXECUTABLE.md) y [dependencias heredadas](../LEGACY-DEPENDENCY-INVENTORY.md).
2. Código: `routes/web.php` (Laravel), `symfony/config/routes.yaml`, los **28** archivos `symfony/src/Http/Controller/*.php` con atributos `#[Route]`, `symfony/src/Http/Reports/CrossModuleReportController.php`, `symfony/frontend/admin/*.tsx` y `symfony/templates/**/*.twig`. Se cotejaron consumidores React y referencias `path('nombre_ruta')` de Twig: **no todo consumidor está escrito como una URL literal**.
3. Evidencia adicional existente: [reconciliación #906](rutas-sin-interfaz.md), [E2E #342](epica-342.md), [paridad #350](epica-350.md), [seguridad #346](epica-346.md), [presencia de rutas #904](../../scripts/s4-route-presence.py), [incidente #903](https://github.com/pl0n3r/GrindFlow/issues/903), [incidente #917](https://github.com/pl0n3r/GrindFlow/issues/917).
4. Se distinguen **CÓDIGO** (existe), **ENRUTADO** (está incluido en el import del framework), **UI** (hay un consumidor humano o de máquina justificado), **CI** (prueba ejecutada sobre SHA identificable), **DEPLOY** (checkout y dependencias efectivas comprobadas en el host) y **OPERATIVO** (flujo real verificado). Ninguna columna implica automáticamente la siguiente.
5. **NO_VERIFICABLE** es ausencia de evidencia suficiente, *no* ausencia de funcionalidad. Las rutas reservadas a `APP_ENV=test`, los contratos futuros y los proveedores deliberadamente apagados **no** se convierten en defectos ficticios.

**Límites:** lectura de código y reportes ya publicados. No se ejecutaron formularios POST/PUT/DELETE contra producción, sesiones autenticadas productivas, consulta de datos reales, diagnósticos con secretos, backup, despliegue, migraciones ni acciones de proveedor. El estado operativo puede haber cambiado después del corte; los incidentes requieren una nueva observación antes de cerrarse.

## Inventario de rutas y componentes en fuente

- **Symfony:** **70 atributos** `#[Route]` declarados en 28 controladores bajo el directorio importado `src/Http/Controller/`. Son candidatas de enrutamiento del *árbol de código*, no 70 respuestas HTTP productivas verificadas. El import de `staff_ops` en `routes.yaml` puede aportar rutas adicionales; por ello no se interpreta «70» como un inventario exhaustivo del kernel compilado.
- **Reporte adicional:** `CrossModuleReportController.php` contiene **un atributo extra** `GET /api/admin/reports/cross-module`, **fuera del directorio importado** (`src/Http/Reports/`). Resultado comprobable en fuente: **NO_ENRUTADO**; ni invocar su método en un test ni ver una anotación `#[Route]` demuestra un GET funcional. Ver [#348](https://github.com/pl0n3r/GrindFlow/issues/348) y [#903](https://github.com/pl0n3r/GrindFlow/issues/903).
- **Interfaz Symfony:** `AdminApp.tsx` monta paneles de Vault, Colecciones, Programación/Planner, Handoff, Reglas, Piloto y Planes bajo controles de permisos; `WorkspaceNavigation.tsx` define vínculos a secciones. Las plantillas Twig también consumen rutas por nombre. Los **11 componentes TSX principales** inspeccionados no sustituyen una prueba en navegador, y su presencia no garantiza que el bundle servido en el host los contenga.
- **Laravel y heredados:** `routes/web.php` mantiene login, dashboard, Vault, Scheduler, Distribution, Traffic, Finance, administración, diagnósticos y direct upload. Además existe código Next.js, workers y migraciones Supabase protegido en [inventario de dependencias](../LEGACY-DEPENDENCY-INVENTORY.md). **No hubo cutover certificado**; una capacidad sin par Symfony puede seguir presente en Laravel.

### Controladores Symfony auditados (28/28, 70 declaraciones)

| Grupo / controlador | Declaraciones `#[Route]` | Superficies de API/página documentadas | Consumidor o límite |
| --- | ---: | --- | --- |
| AccountSecurityController | 1 | `POST /api/admin/profile/password` | `AdminApp.tsx` |
| AdminContextController | 2 | `GET /api/admin/context`, `POST /api/admin/organization/name` | `AdminApp.tsx` |
| AdminController | 1 | `GET /admin` | Twig/React admin |
| ContentReviewController | 1 | `PUT /api/admin/content-reviews/{assetId}` | `WeeklyPlannerPanel.tsx` |
| ContentRuleController | 3 | `/api/admin/rules/weekly` GET/PUT y `weekly/preview` GET | `RulesPanel.tsx`, `WeeklyPlannerPanel.tsx` |
| DataSubjectRequestsController | 3 | `/api/admin/privacy/data-subject-requests/*` | Solo `APP_ENV=test`; no disponible como flujo productivo, deliberadamente |
| DirectUploadController | 2 | `/api/admin/vault/direct-upload/intent` y `complete` POST | Backend preparado; B2 no configurado [#146](https://github.com/pl0n3r/GrindFlow/issues/146) |
| DistributionAuthorizationController | 1 | `PUT /api/admin/distribution-authorizations/{assetId}` | `WeeklyPlannerPanel.tsx` |
| HealthController | 1 | `GET /health` | CI/monitor técnico |
| HomeController | 1 | `GET /` | Twig |
| LibraryCollectionsController | 7 | Colecciones, assets y variantes de maestro | UI parcial en `LibraryCollectionsPanel.tsx`; ver #906 |
| LibraryHistoryController | 2 | Permisos de biblioteca e historial Vault | API de permisos preparada, historial en Vault |
| LibraryLifecycleController | 3 | Ciclo de vida, transición y purge de Vault | Control de permisos / regresiones, sin afirmar UI universal |
| LibraryNetworkRulesController | 3 | Reglas de red y reutilización de Vault | UI de Vault/contratos, sujeto a permisos |
| LoginController | 2 | `/login`, `/logout` | Formularios Twig |
| ManualDestinationController | 3 | Destinos manuales GET/POST/PUT | `ManualHandoffPanel.tsx`, `ScheduleDraftPanel.tsx` |
| ManualHandoffController | 1 | Handoff del borrador PUT | `ScheduleDraftPanel.tsx` |
| ManualHandoffQueueController | 1 | Cola manual GET | `ManualHandoffPanel.tsx`, `ScheduleDraftPanel.tsx` |
| OrganizationController | 2 | Organizaciones y selección | Formularios Twig |
| PasswordRecoveryController | 2 | Recuperación de contraseña | Twig; correo productivo no ensayado |
| PilotWeeklySummaryController | 2 | Resumen del piloto y CSV | `PilotWeeklySummaryPanel.tsx` |
| PlanLimitsAdminController | 2 | `/api/admin/plans` GET y `/plans/{plan}` PUT | `PlanLimitsPanel.tsx`; migración/host por validar |
| PreviewController | 1 | `GET /preview` | Twig/preview |
| PrivacyRightsController | 1 | `GET /privacidad-y-mis-derechos` | Página informativa Twig, no formulario DSR |
| ProfileController | 1 | `POST /api/admin/profile/name` | `AdminApp.tsx` |
| PublishingAutomationController | 2 | `/api/admin/rules/automation` GET/PUT | `RulesPanel.tsx`, `WeeklyPlannerPanel.tsx` |
| ScheduleDraftController | 6 | Schedules, readiness, delivery intent, publicación y cancelación | `ScheduleDraftPanel.tsx`; proveedor externo no ensayado |
| VaultController | 13 | Vault CRUD y acciones de nombre/nota/uso/estado/trash/restore | `VaultPanel.tsx`; almacenamiento real no certificado |

Los recuentos son **atributos de ruta**, no métodos HTTP individuales cuando un atributo admite más de un método. Ciertos `/api/admin/*` también están asociados a servicios y fixtures sintéticos; la vinculación de una pantalla no es evidencia de autorización, persistencia o éxito productivo.

## Matriz de capacidades: de lo desarrollado a lo disponible

| Dominio | Laravel / legado | Symfony y consumidor visible | Evidencia técnica | Brecha y veredicto de disponibilidad |
| --- | --- | --- | --- | --- |
| **Identidad/tenant** | `/login`, sesión y organizaciones | `/login`, `/admin`, `/api/admin/context`; Twig + `AdminApp` | [paridad GF-MIG-003](../PARITY-MATRIX-EXECUTABLE.md), `IdentityLoginTest.php`, #342 | **CÓDIGO + UI + test sintético**; autenticación y roles productivos: **NO_VERIFICABLE**. Anónimos 500 reportados [#917](https://github.com/pl0n3r/GrindFlow/issues/917). |
| **Vault e ingesta** | `/organizations/{organizationId}/vault` | `/api/admin/vault`; `VaultPanel.tsx` | `VaultTest.php`, [recorrido E2E sintético](../../symfony/tests/php/MvpVaultToDistributionJourneyTest.php) | **CÓDIGO + UI + pruebas**; upload directo B2 fail-closed [#146](https://github.com/pl0n3r/GrindFlow/issues/146); 500 anónimo reportado [#917](https://github.com/pl0n3r/GrindFlow/issues/917). Producción integral: **NO_VERIFICABLE**. |
| **Procesamiento multimedia** | Workers/colas, pipelines legacy | Sin par HTTP Symfony equivalente en la matriz de paridad | [paridad](../PARITY-MATRIX-EXECUTABLE.md), [legacy](../LEGACY-DEPENDENCY-INVENTORY.md) | **PARIDAD DIFERIDA**; no probar procesamiento real por ver el worker en Git. [#350](https://github.com/pl0n3r/GrindFlow/issues/350). |
| **Reglas y compliance** | Scheduler y validaciones legacy | `/api/admin/rules/weekly`, `/api/admin/rules/automation`; `RulesPanel`/Planner | `PublishingAutomationRuleTest.php` y paneles | **UI con endpoints declarados; equivalencia total DIFERIDA**. `rules/automation` figuró con 404 S4 [#903](https://github.com/pl0n3r/GrindFlow/issues/903). |
| **Scheduling** | `/organizations/{organizationId}/scheduler` | `/api/admin/schedules`; `ScheduleDraftPanel.tsx` | `MvpSchedulingStateTransitionsE2eTest.php`, #342 | **CÓDIGO + UI + E2E sintético**; publicación/proveedor/cancelación en entorno real **NO_VERIFICABLE**. |
| **Distribution y handoff** | `/organizations/{organizationId}/distribution` | `/api/admin/distribution-authorizations/{assetId}`, handoff/cola manual; `WeeklyPlannerPanel`, `ManualHandoffPanel` | `MvpScheduleToDistributionE2eTest.php`, #342 | **Cobertura de autorización/handoff offline**, no paridad de motor completo ni despacho externo. [#350](https://github.com/pl0n3r/GrindFlow/issues/350). |
| **Traffic** | `/organizations/{organizationId}/traffic`, export CSV | **Sin ruta Symfony equivalente acreditada** en la matriz de paridad | `routes/web.php`; [matriz](../PARITY-MATRIX-EXECUTABLE.md) | **Paridad DIFERIDA**, no afirmar que falta en Laravel; comprobar legacy hasta cutover [#350](https://github.com/pl0n3r/GrindFlow/issues/350). |
| **Finance** | `/organizations/{organizationId}/finance`, reconciliación CSV | **Sin ruta Symfony equivalente acreditada** | `routes/web.php`; [matriz](../PARITY-MATRIX-EXECUTABLE.md) | **Paridad DIFERIDA**; no confundir fuente Laravel con integración administrativa Symfony. [#350](https://github.com/pl0n3r/GrindFlow/issues/350). |
| **UI/producto/reportes** | `/dashboard` | `/admin`; React con paneles condicionales; piloto semanal. Reporte cross-module separado | `PilotWeeklySummaryTest.php`, [#906](rutas-sin-interfaz.md) | **Frontend conectado en código**, no certificación de navegador productivo. **Reporte cross-module NO_ENRUTADO** en la importación actual, UI futura [#348](https://github.com/pl0n3r/GrindFlow/issues/348). |
| **Entrega/observabilidad** | `/health`, `/_deployment`, admin system | `/health`, S4 bridge | `ReleaseShaHealthTest.php`, [presencia de rutas](../../scripts/s4-route-presence.py), #903/#904 | **Existe monitoreo/código**; la versión humana y un workflow verde no prueban SHA Symfony desplegado ni disponibilidad de todas las rutas. **NO_VERIFICABLE** para este corte. |

**Interpretación de los 10 dominios:** cinco están clasificados como `RUTA_Y_PRUEBA_EN_CODIGO` y cinco como `DIFERIDA` en [GF-MIG-003](../PARITY-MATRIX-EXECUTABLE.md). Es un contrato **offline**, no un índice de porcentaje de producto completo ni una medición del host.

## Reconciliación de interfaz ↔ backend: no duplicar #906

El análisis anterior [#906](rutas-sin-interfaz.md) trató **19 candidatas** sin referencia literal: **7 CON_INTERFAZ**, **12 BACKEND_PREPARADO**, **0 HUERFANA** *en esa muestra*. Se verificaron nombres de rutas Twig (`path('...')`) que una búsqueda de URLs literal había omitido. Dentro de las 12 preparadas hay fines distintos: endpoints para máquinas/CI, variantes de Library sin pantalla propia, direct upload pendiente de proveedor, DSR deliberadamente test-only, y reporte cross-module **no importado**. No se deben crear 12 pantallas ni exponer endpoints deliberadamente inactivos para hacer pasar un porcentaje.

**Diferencias confirmadas y clasificadas:**
- `NO_ENRUTADO` (fuente): `GET /api/admin/reports/cross-module` en `src/Http/Reports`; `routes.yaml` no importa ese directorio. **#348** es su roadmap UI/activación. Una corrección exige su propio issue/claim de enrutador + test HTTP Kernel, no una edición accidental en esta auditoría.
- `BACKEND_PREPARADO_INTENCIONAL`: DSR `/api/admin/privacy/data-subject-requests/{csrf,export,deletion}`, disponible para tests sintéticos pero no como flujo legal/productivo; vista de privacidad es informativa. Referencias [#799](https://github.com/pl0n3r/GrindFlow/issues/799), [#814](https://github.com/pl0n3r/GrindFlow/issues/814), [#457](https://github.com/pl0n3r/GrindFlow/issues/457).
- `BACKEND_PREPARADO_INTENCIONAL`: `/api/admin/vault/direct-upload/{intent,complete}` tiene contrato y falla cerrado sin storage real, [#146](https://github.com/pl0n3r/GrindFlow/issues/146); no provisionar Backblaze B2 antes de la decisión de live.
- `RUNTIME_404` **registrado, no reproducido en esta auditoría**: [#903](https://github.com/pl0n3r/GrindFlow/issues/903) observó 404 en rutas administrativas nuevas. Su [PR #907](https://github.com/pl0n3r/GrindFlow/pull/907) ya trabaja la reconciliación de runtime. Aun si `S4 Runtime Prepare` marca `success`, comprobar HTTP real **después** y separar el endpoint Reports no registrado por código.
- `RUNTIME_500` **registrado, no reproducido en esta auditoría**: [#917](https://github.com/pl0n3r/GrindFlow/issues/917) sigue abierto por 500 anónimo en tres APIs; los guards 401 existentes en fuente y los tests no sustituyen la causa raíz o la respuesta S4 actual.
- `PARIDAD_DIFERIDA`: procesamiento, compliance, distribución completa, Traffic y Finance (véase [#350](https://github.com/pl0n3r/GrindFlow/issues/350), [#913](https://github.com/pl0n3r/GrindFlow/issues/913)); ningún retiro Laravel/Next/worker está autorizado mientras haya dependencias `UNKNOWN`.

## Estado de pruebas, CI y despliegue

| Capa | Evidencia existente | Qué **no** prueba |
| --- | --- | --- |
| Código y rutas | Corte `main@7a3c3d95...`; controlador, import y consumidores inspeccionados | Compilación del router en Hostinger ni éxito HTTP real |
| Cobertura/QA | [E2E #342](epica-342.md) acredita 5 criterios originales **solo offline**; fixtures sintéticos. [#630](https://github.com/pl0n3r/GrindFlow/issues/630) mantiene requisitos `NO_VERIFICABLE` donde falta cadena de evidencia | Todos los módulos, permisos, navegador y servicios remotos |
| CI | [#350](epica-350.md) registra `validate` verde en `main@5acbfc01...` (corte **anterior** a este); el PR de #918 debe validar **su propio HEAD exacto** | CI de otro SHA, deploy o aprobación de go-live |
| Despliegue | [#903](https://github.com/pl0n3r/GrindFlow/issues/903) documenta inconsistencias S4; `/s4/health` con `release_sha: null` en la lectura anterior | Que `symfony/vendor`, router y bundle UI sean iguales al árbol actual |
| Producción funcional | La auditoría actual no ejecutó un E2E autenticado contra Hostinger | Ninguna afirmación «100 % disponible» / «VALIDATED_IN_PRODUCTION» |

### Brechas y responsables sin duplicación

| Prioridad | Brecha verificable | Issue canónico | Condición de salida real |
| --- | --- | --- | --- |
| P0 operativo | S4 con 404 en rutas esperadas (algunas realmente importables) | [#903](https://github.com/pl0n3r/GrindFlow/issues/903) · [PR #907](https://github.com/pl0n3r/GrindFlow/pull/907) | Reobservar HTTP 401/302/200 según contrato, comprobar versión/SHA y reparar causa con backup/rollback autorizados |
| P0 operativo | 500 anónimo donde debería existir 401 JSON | [#917](https://github.com/pl0n3r/GrindFlow/issues/917) | Diagnóstico de runtime y tres respuestas 401 verificadas, sin datos sensibles |
| P1 integración | Reporte cross-module fuera de `routes.yaml`, sin UI | [#348](https://github.com/pl0n3r/GrindFlow/issues/348), [#679](https://github.com/pl0n3r/GrindFlow/issues/679) | Slice de activación aprobado, prueba por kernel y consumidor real, sin invadir #903 |
| P1 migración | Cinco dominios de paridad diferida; retiro legacy no sustentado | [#350](https://github.com/pl0n3r/GrindFlow/issues/350) y [#913](https://github.com/pl0n3r/GrindFlow/issues/913) | Comportamiento comparable y E2E por dominio, sin retirar legado antes de evidencia |
| P1 cobertura | CI E2E offline, no toda operación real | [#342](https://github.com/pl0n3r/GrindFlow/issues/342), [#630](https://github.com/pl0n3r/GrindFlow/issues/630) | Matriz requisito→caso→HEAD→entorno, negativos tenant/rol y smoke por módulo |
| Puerta intencional | B2 no configurado, DSR no operativo por diseño | [#146](https://github.com/pl0n3r/GrindFlow/issues/146), [#457](https://github.com/pl0n3r/GrindFlow/issues/457) | Decisiones humanas de proveedor/live/privacidad y pruebas autorizadas, no activación implícita |

## Conclusión y criterio objetivo para declarar «todo disponible»

**Resultado auditado:** hay integración **demostrable a nivel de fuente** para los paneles principales de Symfony y pruebas HTTP/E2E sintéticas importantes; también hay **brechas reales de enrutamiento**, **incidentes reportados de disponibilidad S4**, y **paridad Symfony deliberadamente incompleta**. La respuesta a «¿todo lo desarrollado está disponible en el backend?» es **NO ACREDITADO** a fecha de este corte. No existe fundamento para un porcentaje de completitud productiva.

Solo declarar **VALIDATED_IN_PRODUCTION** por capacidad cuando se haya demostrado, con identidad exacta de SHA, 1) contrato/requisito y ruta cargada en el kernel, 2) consumidor visible o justificación de API de máquina, 3) HTTP 2xx/4xx esperado y pruebas negativas de permisos, 4) persistencia/estados y regresión reproducible con fixtures autorizados, 5) despliegue/S4 y smoke observado sin 404/500 imprevistos, 6) manejo de proveedor/almacenamiento aprobado cuando aplique. Si falta una evidencia, registrar `NO_VERIFICABLE` y el issue responsable.

**Este documento no interviene `routes.yaml`, controladores, UI, migraciones, deploy ni proveedor**. Las reparaciones siguen los claims/V3 individuales y los gates `PLAN-AGENTES.md`; se mantiene el incidente abierto hasta observar la respuesta real después de una intervención autorizada. La reversión de la auditoría consiste solo en revertir el commit documental.
