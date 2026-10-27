# GrindFlow · Lista de salida a producción

**Corte documental:** 27-oct-2026 · `main@c30d79df694490cd803383d7341e40ec0ca6f682` · fase `construccion` · [issue #632](https://github.com/pl0n3r/GrindFlow/issues/632).

**Decisión: NO-GO.** Este documento es una **auditoría de preparación offline**: no ejecuta hosting, transacciones, OAuth, DNS, publicaciones, restauraciones ni tratamientos de datos. La existencia del código, un merge, un CI exitoso y el versionado `0.1.219` no acreditan despliegue ni validación en Hostinger.

## Reglas de clasificación

- `LISTO`: solo prueba verificable y contemporánea sobre el **destino productivo**, identidad/release SHA, autorización y resultado terminal; nunca por documentación, mocks o checks aislados.
- `EN_CURSO`: únicamente hoja **ejecutable** con `factory-plan-task`, V3 activa o PR abierto. Un épico, un roadmap `planificado` o un Issue bloqueado no satisfacen este estado.
- `SIN_COBERTURA`: ausencia de hoja canónica tras comprobar duplicados; antes de usarlo se crea y enlaza una hoja ejecutable real con claims exclusivos y contrato de aceptación.
- `DEL_DUEÑO`: falta una cuenta, decisión, consentimiento, gasto, permiso operativo o evidencia de operador autorizado; la frase identifica quién debe actuar sin delegar la decisión.
- `NO_APLICA`: requiere motivo y prueba específica. No se usa para ocultar brechas. La palabra `NO_VERIFICABLE` puede describir evidencia faltante, pero no es un estado de la matriz.

**Normativa:** [decisiones.yml](../decisiones.yml) D-055 a D-062. D-056 separa integración, deploy y validación; D-059 exige puerta legal al cambiar proveedores/finalidades; D-060 fija Symfony 7.4/Doctrine/MariaDB como objetivo, no hosting desplegado. [datos.yml](../datos.yml) sigue con datos pendientes de OWNER.

## Matriz

| Área | Requisito | Estado | Evidencia o faltante | Issue |
| --- | --- | --- | --- | --- |
| Respaldos y restauración | Backup DB nativo antes de mutaciones o cutover | DEL_DUEÑO | OWNER y operador: docs/DEPLOY-HOSTINGER.md describe recibo DB; falta evidencia server-side actual exact-release | [#311](https://github.com/pl0n3r/GrindFlow/issues/311) |
| Respaldos y restauración | Recovery cifrado DB y Vault con freezes y manifiestos | DEL_DUEÑO | OWNER y operador: docs/PRODUCTION-RECOVERY.md exige recovery bundle y confirmación separada; no hay recibo live | [#311](https://github.com/pl0n3r/GrindFlow/issues/311) |
| Respaldos y restauración | Restore drill aislado, RPO/RTO y paridad de datos | DEL_DUEÑO | OWNER y operador: tests offline no prueban restauración auténtica ni tiempo medido | [#311](https://github.com/pl0n3r/GrindFlow/issues/311) |
| Monitoreo y alertas | Health, Deploy Observer y Production Smoke sobre release exacto | DEL_DUEÑO | OWNER y operador: docs/PRODUCTION-OPERATIONS.md exige identidad y fresh checks; reportes stale o UNKNOWN no pasan | [#343](https://github.com/pl0n3r/GrindFlow/issues/343) |
| Monitoreo y alertas | Alertas incidentes 500, cron, colas y receptor operativo | DEL_DUEÑO | OWNER y operador: runbook offline existe; faltan receptor confirmado y observación de alertas reales | [#343](https://github.com/pl0n3r/GrindFlow/issues/343) |
| Dominios, DNS y TLS | Resolver dominio real y certificado HTTPS vigente | DEL_DUEÑO | OWNER y operador Hostinger: comprobar DNS, TLS y redirección HTTPS desde destino sin cambiar registros | [#250](https://github.com/pl0n3r/GrindFlow/issues/250) |
| Dominios, DNS y TLS | CORS/SameSite/cookies para origen real tras deploy | DEL_DUEÑO | OWNER y operador: docs/DEPLOY-HOSTINGER.md no constituye prueba de navegador o configuración activa | [#345](https://github.com/pl0n3r/GrindFlow/issues/345) |
| Secretos y accesos | APP_KEY, DB, OAuth y credenciales de hosting fuera de Git | DEL_DUEÑO | OWNER y operador: verificar provisionado en canal protegido; nunca copiar valores en el Issue | [#250](https://github.com/pl0n3r/GrindFlow/issues/250) |
| Secretos y accesos | Least privilege, rotación y revocación de sesiones y tokens | DEL_DUEÑO | OWNER y operador: establecer cuentas nominativas y validar revocación con permisos reales | [#346](https://github.com/pl0n3r/GrindFlow/issues/346) |
| Costos y límites | Costos por proveedor y presupuesto aprobado | DEL_DUEÑO | OWNER: docs/launch/cost-model.md registra UNKNOWN; falta tarifa, consumo y permiso de gasto | [#353](https://github.com/pl0n3r/GrindFlow/issues/353) |
| Costos y límites | Límites por plan: storage, ancho de banda, colas y llamadas API | DEL_DUEÑO | OWNER y Producto: definir cotas con datos de uso del piloto, no inventar precio ni cuota | [#353](https://github.com/pl0n3r/GrindFlow/issues/353) |
| Privacidad y legal | Responsable y contacto de derechos en datos.yml | DEL_DUEÑO | OWNER y asesor legal: campos [COMPLETAR POR EL DUEÑO] y tratamiento review_required siguen abiertos | [#611](https://github.com/pl0n3r/GrindFlow/issues/611) |
| Privacidad y legal | Bases, consentimiento, proveedores receptores y retención | DEL_DUEÑO | OWNER y asesor legal: #611 cambio material e Instagram Reels pendiente; no hay aprobación legal | [#611](https://github.com/pl0n3r/GrindFlow/issues/611) |
| Privacidad y legal | Borrado/portabilidad y respuesta a solicitudes de titulares | DEL_DUEÑO | OWNER y asesor legal: procedimientos, responsables y evidencias reales no acreditados | [#457](https://github.com/pl0n3r/GrindFlow/issues/457) |
| Soporte y operación diaria | Canal de soporte y responsables asignados | DEL_DUEÑO | OWNER: docs/launch/onboarding-support.md describe roles propuestos, canales y responsables UNKNOWN | [#351](https://github.com/pl0n3r/GrindFlow/issues/351) |
| Soporte y operación diaria | SLA, guardias y gestión de incidencias antes de venta | DEL_DUEÑO | OWNER: SLA solo propuestos; aprobar horas, escalamiento y obligaciones verificables | [#351](https://github.com/pl0n3r/GrindFlow/issues/351) |
| Migración de datos | Cutover Laravel a Symfony con paridad por tenant | DEL_DUEÑO | OWNER y operador: docs/STACK-TRANSITION-SYMFONY.md define destino; necesita autorización y prueba real de paridad | [#350](https://github.com/pl0n3r/GrindFlow/issues/350) |
| Migración de datos | Migración aditiva de datos y reconciliación de filas | DEL_DUEÑO | OWNER y operador: no ejecutar SQL/DDL productivo sin backup probado y gate apropiado | [#350](https://github.com/pl0n3r/GrindFlow/issues/350) |
| Reversión y apagado | Rollback del release anterior con SHA conservado | DEL_DUEÑO | OWNER y operador Hostinger: probar rollback observable; una receta de ops/factory no demuestra restauración efectiva | [#250](https://github.com/pl0n3r/GrindFlow/issues/250) |
| Reversión y apagado | Deshabilitar publicación, detener cron y revocar accesos | DEL_DUEÑO | OWNER y operador: ejecutar smoke de apagado solo con permisos y plan de recuperación | [#250](https://github.com/pl0n3r/GrindFlow/issues/250) |
| Seguridad y pruebas | Tenant isolation, roles, CSRF y negative tests del MVP | DEL_DUEÑO | OWNER y QA de destino: #346 contempla revisión transversal; tests de CI no prueban hosting real | [#346](https://github.com/pl0n3r/GrindFlow/issues/346) |
| Seguridad y pruebas | Revisión de seguridad grave y findings exact-HEAD | DEL_DUEÑO | OWNER y Seguridad: SonarCloud/CodeQL ayudan, falta verificación terminal de servicio instalado y autorizaciones | [#346](https://github.com/pl0n3r/GrindFlow/issues/346) |
| Seguridad y pruebas | Cobertura HTTP de TrafficController con V3 vigente | EN_CURSO | Hoja ejecutable #575, PR #589 abierto y pruebas reclamada; no acredita go-live por sí sola | [#575](https://github.com/pl0n3r/GrindFlow/issues/575) |
| Accesibilidad y rendimiento | Teclado, foco, móvil y errores en journey real | DEL_DUEÑO | OWNER y QA: #344 es roadmap planificado; falta auditoría web real autenticada sobre artefacto desplegado | [#344](https://github.com/pl0n3r/GrindFlow/issues/344) |
| Accesibilidad y rendimiento | Carga, latencia y límites de hosting con datos medidos | DEL_DUEÑO | OWNER y operador: benchmarks de CI no sustituyen medición productiva sin PII | [#345](https://github.com/pl0n3r/GrindFlow/issues/345) |
| Smoke de producción | /health con SHA desplegado, login y acceso de tenant | DEL_DUEÑO | OWNER y operador: Production Smoke debe certificar identidad/frescura real; UNKNOWN es NO-GO | [#250](https://github.com/pl0n3r/GrindFlow/issues/250) |
| Smoke de producción | Flujo mínimo end-to-end del piloto con cliente autorizado | DEL_DUEÑO | OWNER: requiere cuentas de prueba y autorización explícita para tráfico; no usar clientes reales sin consentimiento | [#271](https://github.com/pl0n3r/GrindFlow/issues/271) |
| Almacenamiento media y CORS | Bucket externo, IAM y disponibilidad de direct upload | DEL_DUEÑO | OWNER y operador: #146 bloqueado por configuración; docs/MEDIA-STORAGE-CORS-PREFLIGHT.md solo inspecciona JSON offline | [#146](https://github.com/pl0n3r/GrindFlow/issues/146) |
| Almacenamiento media y CORS | PUT firmado y preflight OPTIONS desde el origin real | DEL_DUEÑO | OWNER y operador: falta acceso autorizado a S3 y prueba navegador CORS en destino; wildcard inválido | [#345](https://github.com/pl0n3r/GrindFlow/issues/345) |
| Almacenamiento media y CORS | Quick Upload y Vault privado para JPEG/PNG 8MiB | DEL_DUEÑO | OWNER y operador: docs/PILOT-MEDIA-READINESS.md exige evidencia del runtime real; no inferir por fixtures | [#250](https://github.com/pl0n3r/GrindFlow/issues/250) |
| Conectores Dropbox y Drive | OAuth/callback Dropbox en app console real | DEL_DUEÑO | OWNER y operador de cuenta Dropbox: docs/MEDIA-CONNECTORS.md confirma flujo de código, callbacks reales no probados | [#347](https://github.com/pl0n3r/GrindFlow/issues/347) |
| Conectores Dropbox y Drive | OAuth, scopes y refresh Google Drive autorizados | DEL_DUEÑO | OWNER y operador de cuenta Google: tokens y refresh solo pueden verificarse en cuenta de prueba autorizada | [#347](https://github.com/pl0n3r/GrindFlow/issues/347) |
| Conectores Dropbox y Drive | Cuotas, cursor incremental, 429/backoff y revocación | DEL_DUEÑO | OWNER y operador: CI usa fakes, se requieren tarifas/cuotas y consentimiento de usuarios reales | [#347](https://github.com/pl0n3r/GrindFlow/issues/347) |
| Publicación en redes | Páginas de prueba autorizadas para publicación real | DEL_DUEÑO | OWNER: cuentas y páginas de prueba, permisos OAuth, términos de plataforma y consentimiento pendientes | [#250](https://github.com/pl0n3r/GrindFlow/issues/250) |
| Publicación en redes | No publicar contenido sin permiso y audit trail de entrega | DEL_DUEÑO | OWNER y operador: validar cadena de autorización real antes de permitir triggers; los tests son offline | [#250](https://github.com/pl0n3r/GrindFlow/issues/250) |
| Publicación en redes | Reels Instagram y proveedor material de privacidad | DEL_DUEÑO | OWNER y legal: #611 mantiene puerta material abierta; no emitir publicaciones reales hasta decisión | [#611](https://github.com/pl0n3r/GrindFlow/issues/611) |
| Soporte y onboarding | Onboarding de los primeros cinco creadores del piloto | DEL_DUEÑO | OWNER: docs/launch/onboarding-support.md es plan, faltan participantes autorizados y resultados #271 | [#271](https://github.com/pl0n3r/GrindFlow/issues/271) |
| Soporte y onboarding | Canal de incidentes, ayuda y responsabilidades por modalidad | DEL_DUEÑO | OWNER: roles y canal reales UNKNOWN; requiere confirmación, no SLA ficticio | [#351](https://github.com/pl0n3r/GrindFlow/issues/351) |
| Migración de Hostinger | Runtime PHP/Composer/DB y permisos Symfony en destino | DEL_DUEÑO | OWNER y operador: D-060 aprueba Symfony 7.4, pero no certifica soporte PHP 8.5 en Hostinger | [#350](https://github.com/pl0n3r/GrindFlow/issues/350) |
| Migración de Hostinger | Sustitución reversible de front-controller y rutas legacy | DEL_DUEÑO | OWNER y operador: falta comparación funcional, ruta de retorno y decisión de cutover sin escritores dobles | [#350](https://github.com/pl0n3r/GrindFlow/issues/350) |
| Auditoría de lanzamiento | Matriz de cobertura por requisito, código, CI y hosting | EN_CURSO | Hoja ejecutable #630, PR #631 abierto con reserva V3 en rutas distintas | [#630](https://github.com/pl0n3r/GrindFlow/issues/630) |
| Auditoría de lanzamiento | Lista NO-GO con test de evidencias y resumen | EN_CURSO | Hoja ejecutable #632, reserva V3 propia y diff exclusivo; fusionar no equivale a GO | [#632](https://github.com/pl0n3r/GrindFlow/issues/632) |
| Decisión de lanzamiento | Aprobación explícita de readiness/piloto bajo gate del dueño | DEL_DUEÑO | OWNER: #250 registra piloto no autorizado; phase=construccion y #356 planificado no conceden go-live | [#356](https://github.com/pl0n3r/GrindFlow/issues/356) |

## Resumen final

| Estado | Filas |
| --- | ---: |
| LISTO | 0 |
| EN_CURSO | 3 |
| SIN_COBERTURA | 0 |
| DEL_DUEÑO | 40 |
| NO_APLICA | 0 |
| TOTAL | 43 |

**Hojas creadas por falta de cobertura en este corte:** 0. No hay filas `SIN_COBERTURA`: todos los pendientes enumerados están sujetos a decisión/cuenta/evidencia operativa o a hojas ejecutables existentes (#575, #630, #632). Esta clasificación **no afirma cobertura técnica completa**. Al aparecer una brecha técnica no ligada a decisión, será necesario comprobar duplicados y materializar su hoja canónica antes de pasarla a `SIN_COBERTURA`. Los roadmaps #343–#356, aunque útiles como fuentes, no se marcan falsamente `EN_CURSO`.

**Bloquea go-live:** identidad de despliegue + smoke autenticado reales; backup/restauración/rollback sobre datos permitidos; OWNER/Hostinger/DOMINIO/TLS/costos/secretos; decisión legal de #611 y datos.yml; pruebas reales de bucket/CORS, Dropbox y Drive; páginas sandbox y derechos sobre contenido; paridad Laravel→Symfony; uso piloto y soporte con participantes autorizados; autorización explícita de lanzamiento. `UNKNOWN`, `STALE` y `BLOCKED_TARGET_ENV` significan NO-GO, no aprobaciones implícitas.

**Secuencia segura:** (1) cerrar documentalmente #630 y #632 con CI exact-HEAD, (2) separar decisiones pendientes de pruebas offline, (3) recopilar permisos y recibos seguros del OWNER y operador, (4) evaluar salud, backup/restore, seguridad y pruebas real-runtime sobre release exacto sin filtrar secretos, (5) solo ante nueva puerta humana legítima valorar el go-live. No usar esta lista para habilitar `FACTORY_DEPLOY_ENABLED`, cambiar DNS, gastar o utilizar cuentas reales.

**Fuentes:** [PRODUCTION-OPERATIONS](PRODUCTION-OPERATIONS.md), [PRODUCTION-RECOVERY](PRODUCTION-RECOVERY.md), [DEPLOY-HOSTINGER](DEPLOY-HOSTINGER.md), [STACK-TRANSITION-SYMFONY](STACK-TRANSITION-SYMFONY.md), [PILOT-MEDIA-READINESS](PILOT-MEDIA-READINESS.md), [MEDIA-CONNECTORS](MEDIA-CONNECTORS.md), [MEDIA-STORAGE-CORS-PREFLIGHT](MEDIA-STORAGE-CORS-PREFLIGHT.md), [cost-model](launch/cost-model.md), [onboarding-support](launch/onboarding-support.md), [PRODUCTION-SMOKE-AUTH-TRIAGE](PRODUCTION-SMOKE-AUTH-TRIAGE.md); [roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2), [#146](https://github.com/pl0n3r/GrindFlow/issues/146), [#250](https://github.com/pl0n3r/GrindFlow/issues/250), [#345](https://github.com/pl0n3r/GrindFlow/issues/345), [#350](https://github.com/pl0n3r/GrindFlow/issues/350), [#351](https://github.com/pl0n3r/GrindFlow/issues/351), [#611](https://github.com/pl0n3r/GrindFlow/issues/611).
