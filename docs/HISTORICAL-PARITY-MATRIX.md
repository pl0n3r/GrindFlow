# Matriz histórica de paridad de GrindFlow

> Fotografía de trazabilidad recuperada de PR #215 y reconciliada para V0.1.182.
> Origen histórico: primer commit disponible `2c76f0d3ccad69cd0572f1dfe41c5dabd0410c33`.
> Corte operativo de referencia: `main@460697ff5524b4a81a0b5ebc0ad90be033d5a3f1`, versión declarada V0.1.181.
> Deploy Observer #160 observó ese SHA exacto; Production Smoke #165 también alcanzó exact-main, pero sigue fail-closed con `MIGRATIONS_PENDING=3`. Eso no equivale a producción verde.
> Esta matriz no es un backlog automático: Roadmap #2, #250 y los requisitos vigentes deciden prioridad y alcance.

## Cómo leer la matriz

- **S — cubierto en Symfony:** existe comportamiento verificable en el runtime objetivo Symfony 7.4; no implica cutover ni producción.
- **L — cubierto en Laravel/coexistencia:** existe comportamiento verificable en el runtime que continúa operativo durante la transición.
- **P — parcial:** existe una parte útil, pero falta equivalencia de reglas, UX, datos, integración o validación externa.
- **D — decisión pendiente:** no debe implementarse por inercia desde el legado.

Un nombre de módulo similar no acredita paridad. La evidencia debe ser código, esquema, prueba o entrega concreta. CI, deploy y producción se demuestran por separado; una capacidad fusionada tampoco acredita que esté habilitada o validada con datos/proveedores reales.

## Matriz reconciliada

| ID | Capacidad histórica | Estado actual | Evidencia actual y brecha |
| --- | --- | --- | --- |
| HIST-01 | Espacios y navegación diferenciados por función/rol | **P / S** | Symfony contiene `WorkspaceNavigation.tsx` y `AdminContextController.php`, con contexto explícito de organización. No se recuperan 1:1 los antiguos paneles `studio/model/editor`; el contrato vigente se organiza por tenant y permisos. |
| HIST-02 | Perfil operativo distinto de organización/membresía | **L** | `app/Models/OperationalProfile.php` materializa la entidad tenant-scoped aprobada desde #220. La identidad del perfil no se confunde con usuario o membresía. Su futura equivalencia/cutover Symfony sigue separada. |
| HIST-03 | Triaje explícito ante titularidad ambigua | **L** | `VaultTriageItem.php` y `VaultOwnershipTriage.php` materializan la cola de triaje: un asset ambiguo no debe recibir titular por inferencia y la asignación autorizada queda auditable. |
| HIST-04 | Biblioteca multimedia privada y tenant-safe | **S + L** | Symfony `VaultController.php` y el Vault Laravel mantienen superficies aisladas por organización; blobs/activos y autorización siguen siendo contratos distintos del almacenamiento público. |
| HIST-05 | Subida directa/móvil con límites y autorización | **L + P/S** | #232 completó el flujo móvil invitado sin sesión con preview/rechazo/retiro; Laravel conserva `GuestMobileUploadController.php`, `GuestMobileUploadIngestor.php` y usos persistentes de grants. Symfony tiene Direct Upload, pero object storage real continúa condicional en #146. |
| HIST-06 | Programación por reglas y slots, con razones de no elegibilidad | **S + L / P** | Symfony contiene `ScheduleDraftController.php`, `WeeklySlotCalculator` y UI de planner; Laravel conserva `ContentScheduler.php`. La automatización recurrente del MVP sigue en #268, detrás del vertical slice externo. |
| HIST-07 | Autorización explícita antes de distribución | **S** | `DistributionAuthorizationController.php` mantiene autorización interna separada de una publicación externa. Autorizar dentro de GrindFlow no demuestra derechos en Meta ni éxito remoto. |
| HIST-08 | Distribución a plataformas externas y manejo seguro de fallos | **P / S + L** | #266 incorporó `FacebookPageProvider.php` y `FacebookPagePublicationService.php` con ledger/idempotencia fail-closed y transporte fake en CI. Sigue sin conectar Composer/Schedule Draft ni acreditar una publicación live; esa brecha es #260 y está bloqueada por #238. Laravel conserva Distribution/Sandbox durante coexistencia. |
| HIST-09 | Enlaces rastreados y atribución de tráfico | **L / P** | Laravel conserva `TrackedLinkManager.php`, `TrafficAttributionRecorder.php` y fingerprint de visitante. El resumen piloto Symfony no debe convertir ausencia de read-model Traffic en cero inventado. |
| HIST-10 | Analítica operativa y resumen de piloto | **S / P** | `PilotWeeklySummaryController.php` y `PilotWeeklySummaryPanel.tsx` ofrecen resumen tenant-safe de señales disponibles. No deben presentar eventos internos como publicaciones, conversiones o métricas externas. |
| HIST-11 | Libro financiero / conciliación | **L / P** | Laravel conserva `FinanceLedgerManager.php` y `FinanceReconciliationReport.php`. No hay evidencia de paridad financiera Symfony completa; cualquier port debe preservar moneda, UTC y trazabilidad. |
| HIST-12 | Conectores cloud y credenciales seguras | **L / P** | Laravel mantiene conexiones/ingesta de medios y adaptadores para fuentes cloud. Su existencia no equivale a migración Symfony ni autoriza copiar tokens; #194 dejó las reglas canónicas en docs enlazados. |

## Cambios frente al dossier histórico

- **HIST-02/HIST-03 ya no son solo intención histórica:** el perfil operativo y el triaje existen en Laravel como entidades/servicios tenant-scoped.
- **HIST-05 avanzó materialmente:** el flujo móvil invitado sin sesión quedó completado por #232; object storage real sigue siendo una capacidad separada y fail-soft.
- **HIST-08 avanzó en Symfony:** Facebook Pages ya tiene port/provider/ledger seguro desde #266, pero no se declara publicación externa hasta completar #260 y su evidencia live.
- La compactación #194 no eliminó estas reglas: las movió a documentos canónicos y añadió un presupuesto local de contexto para `AGENTS.md`.

## Brechas reales vigentes

1. **Producción plenamente verde:** #238 sigue abierto con tres migraciones pendientes; antes de cualquier schema adicional se exige Backup → Migration → Smoke=0.
2. **Composer → provider → evidencia externa:** #260 debe conectar Schedule Draft/Composer al provider Facebook y demostrar una publicación autorizada fuera de CI.
3. **Automatización recurrente:** #268 depende de #260 y debe reutilizar Scheduler Symfony.
4. **Dashboard de piloto con entregas reales:** #270 depende de #260 y no puede inventar métricas ausentes.
5. **Traffic/Finance/Conectores en Symfony:** continúan como paridad parcial mientras la coexistencia Laravel siga siendo la única evidencia funcional.
6. **Object storage:** #146 permanece condicional; Quick Upload evita tratarlo como bloqueo universal del MVP.

## Capacidades que no deben revivirse sin decisión vigente

- **Stack Next.js/Supabase/R2/Docker** como arquitectura objetivo. No vuelve a ser objetivo por defecto; el target de código nuevo es Symfony 7.4/MariaDB.
- RLS PostgreSQL como mecanismo obligatorio: el requisito vigente es aislamiento tenant verificable en la arquitectura actual, con pruebas negativas.
- Proveedores concretos del primer commit como compromiso automático de lanzamiento.
- Precios, paquetes o reglas comerciales históricas que no hayan sido ratificadas por una decisión vigente.

## Regla de evidencia para declarar paridad

Antes de marcar una capacidad HIST-* como cerrada:

1. enlazarla a un requisito/Issue vigente y a una implementación concreta;
2. demostrar aislamiento/permiso y estados negativos relevantes;
3. diferenciar `implementado`, `CI verde`, `fusionado`, `desplegado` y `validado en producción`;
4. exigir evidencia externa separada cuando la capacidad dependa de un proveedor real;
5. actualizar esta matriz solo cuando cambie evidencia verificable, no por similitud de nombres.

## Fuentes canónicas

- `docs/REQUIREMENTS.md`
- `docs/GRINDFLOW-SPEC.md`
- `docs/STACK-TRANSITION-SYMFONY.md`
- documentos `docs/AGENT-*.md` enlazados desde `AGENTS.md`
- Roadmap #2 y tracker MVP #250
- primer commit disponible `2c76f0d3ccad69cd0572f1dfe41c5dabd0410c33`

El Issue #6 conserva el contexto histórico completo. Este archivo es la fotografía versionada y revisable; no sustituye el roadmap ni afirma por sí solo estado productivo.
