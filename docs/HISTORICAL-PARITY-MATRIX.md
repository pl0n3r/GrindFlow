# Matriz histórica de paridad de GrindFlow

> Corte: `main@f5b74ddff10569023dd1b2a73de5dd8937bc3192`, versión declarada `0.1.143`.
> Origen histórico: primer commit disponible `2c76f0d3ccad69cd0572f1dfe41c5dabd0410c33`.
> Esta matriz es trazabilidad, no un backlog automático. El roadmap #2 y los requisitos vigentes deciden prioridad y alcance.

## Cómo leer la matriz

- **S — cubierto en Symfony aislado:** existe comportamiento verificable en `symfony/`; no implica cutover ni producción.
- **L — cubierto en Laravel operativo:** existe en el runtime Laravel actual; debe conservarse durante la transición.
- **P — parcial:** existe una parte útil, pero falta equivalencia de reglas, UX, datos o integración.
- **D — decisión/requisito pendiente:** no debe implementarse por inercia desde el legado.

Un nombre de módulo similar no acredita paridad. La evidencia debe ser una ruta, controlador/servicio, esquema y/o prueba que preserve las reglas del comportamiento original. Un estado S o L tampoco acredita despliegue: CI, deploy y producción se demuestran por separado.

## Matriz reconciliada

| ID | Capacidad histórica | Estado actual | Evidencia actual y brecha |
| --- | --- | --- | --- |
| HIST-01 | Espacios y navegación diferenciados por función/rol | **P / S** | Symfony ya tiene navegación compartida y contexto de organización en `symfony/frontend/admin/WorkspaceNavigation.tsx`, `AdminContextController.php` y requisitos GF-UX-002/003. No se recuperan 1:1 los antiguos paneles `studio/model/editor`; la UI vigente se organiza por permisos y tenant. |
| HIST-02 | Perfil personal separado de la organización | **S** | `ProfileController.php` permite cambiar solo el nombre de la cuenta autenticada; Identity mantiene usuario, organización y membresía como entidades distintas. La membresía no se trata como perfil. |
| HIST-03 | Triaje explícito ante titularidad o clasificación ambigua | **P / S** | Vault Symfony conserva clasificación interna (`usage_scope`) y estados como `unclassified`/`needs_review`; no hay evidencia de una pantalla que reproduzca todo el flujo histórico de asignación por perfil. No se debe adivinar titularidad. |
| HIST-04 | Biblioteca multimedia privada y tenant-safe | **S + L** | Symfony `VaultController.php` y `PrivateVaultDirectory` aíslan por organización, mantienen blobs fuera de `public/`, búsqueda, filtros, papelera, cuotas y descarga autorizada. Laravel conserva Vault operativo durante transición. |
| HIST-05 | Subida directa/móvil con límites y autorización | **P / S** | Symfony contiene `DirectUploadController`, `DirectUploadIntentIssuer` y readiness; el adaptador real depende de almacenamiento compatible configurado. La modalidad histórica de enlace invitado sin sesión no se da por recuperada automáticamente. |
| HIST-06 | Programación por reglas y slots, con razones de no elegibilidad | **P / S + L** | Symfony `WeeklySlotCalculator` + `ScheduleDraftController.php` reserva slots UTC y borradores internos sin publicar. Laravel conserva `ContentScheduler`. Faltan equivalencias históricas que no estén ya recogidas como requisitos vigentes; no copiar reglas legacy sin reconciliación. |
| HIST-07 | Autorización explícita antes de distribución | **S** | `DistributionAuthorizationController.php` registra grant/revoke por asset, tenant y actor, y declara `publishes=false`. Es decisión interna; no prueba derechos externos ni publicación. |
| HIST-08 | Distribución a plataformas externas y manejo de fallos | **P / L** | Laravel mantiene `DistributionScheduler`, `PublicationDeliveryManager` y providers; Symfony aislado trabaja con handoff/manual destinations y no hace llamadas a proveedores. La primera publicación externa Symfony sigue fuera de esta evidencia. |
| HIST-09 | Enlaces rastreados y atribución de tráfico | **L / P** | Laravel mantiene `TrackedLinkRedirectController`, `TrackedLinkManager`, `TrafficAttributionRecorder` y fingerprint de visitante. `PilotWeeklySummaryController.php` declara explícitamente que Traffic todavía no existe en Symfony aislado y devuelve `traffic.ready=false`, nunca un cero inventado. |
| HIST-10 | Analítica operativa y resumen de piloto | **S / P** | Symfony ofrece `PilotWeeklySummaryController.php` con agregados diarios tenant-safe de preparación/completado/fallo y CSV acotado. No presenta esos eventos internos como publicaciones externas ni conversiones. |
| HIST-11 | Libro financiero / conciliación | **L / P** | Laravel conserva `FinanceLedgerManager` y `FinanceReconciliationReport`. No se identifica aún equivalente financiero Symfony aislado; cualquier migración debe preservar multimoneda/UTC y trazabilidad. |
| HIST-12 | Conectores cloud y credenciales seguras | **L / P** | Laravel mantiene OAuth/escaneo para Google Drive y Dropbox, políticas HTTP y staging. El objetivo Symfony no debe importar secretos ni asumir que un conector Laravel está migrado por existir en el repo. |

## Brechas que siguen siendo reales

1. **Traffic Symfony:** no implementado en el runtime aislado; el resumen piloto lo declara de forma explícita.
2. **Distribución externa Symfony:** los flujos actuales son autorización/handoff/revisión, no llamadas a plataformas.
3. **Finance Symfony:** el comportamiento operativo continúa en Laravel.
4. **Conectores cloud Symfony:** las integraciones Laravel son referencia de comportamiento, no migración terminada.
5. **Subida invitada sin sesión:** la capacidad histórica no queda satisfecha por una subida directa autenticada.
6. **Triaje por titularidad/perfil:** la clasificación del Vault ayuda, pero no demuestra equivalencia completa con el flujo histórico.

## Capacidades históricas que no deben revivirse sin decisión vigente

- Stack Next.js/Supabase/R2/Docker como arquitectura objetivo. Fue sustituido por la transición Symfony/MariaDB documentada.
- RLS PostgreSQL como mecanismo obligatorio de aislamiento. El requisito vigente es aislamiento verificable por organización en la arquitectura actual, con pruebas negativas.
- Proveedores/plataformas concretos del primer commit como compromiso de lanzamiento. Solo permanecen si el roadmap/requisitos actuales los ratifican.
- Cualquier regla comercial, precio o paquete de prueba que esté marcado como propuesta en la especificación actual.

## Uso obligatorio al migrar un slice

Antes de declarar paridad de una capacidad histórica:

1. identificar el ID HIST-* pertinente y el requisito GF-* vigente;
2. demostrar aislamiento/permiso con prueba negativa;
3. conservar estados, URLs/auditoría e históricos cuando sean parte del contrato;
4. separar `implementado`, `validado en código`, `fusionado`, `desplegado` y `validado en producción`;
5. actualizar esta matriz solo si cambió la evidencia de paridad, no por una entrega de nombre parecido.

## Fuentes canónicas

- `docs/REQUIREMENTS.md`
- `docs/GRINDFLOW-SPEC.md`
- `docs/STACK-TRANSITION-SYMFONY.md`
- `AGENTS.md`
- roadmap #2
- primer commit disponible `2c76f0d3ccad69cd0572f1dfe41c5dabd0410c33`

El Issue #6 conserva el contexto histórico y discusión. Este archivo es la fotografía versionada y revisable de la matriz.
