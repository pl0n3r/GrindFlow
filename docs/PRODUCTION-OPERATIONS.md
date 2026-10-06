# GrindFlow — Operación de producción

Este runbook es la guía durable para observar GrindFlow antes del piloto. Consolida
señales y handoff, pero no sustituye los procedimientos especializados ni amplía
autoridad. UNKNOWN y STALE fallan cerrado.

## Señal agregada Operations Health

El workflow .github/workflows/operations-health.yml corre por schedule o manualmente
desde main. Lee únicamente metadata saneada de GitHub Actions para las fuentes
ci_health, deploy_observer y production_smoke.

Cada señal queda reducida a source, severity, state, freshness, SHA, run ID/URL,
conclusión y timestamp terminal. El evaluador scripts/operations-health.py no hace red
ni lee producción.

Una señal es CURRENT solo cuando corresponde al SHA exacto de main. Una ejecución de
otro SHA es STALE; evidencia ausente es UNKNOWN. Una conclusión terminal no-success
sobre el SHA exacto es DEGRADED. No se inventa GREEN desde silencio, un badge o una
versión visible.

La alerta automática usa un único título estable: [AUTO] GrindFlow Operations Health.
Mientras el problema persiste se actualiza ese mismo Issue; si reaparece después de un
cierre, se reabre el mismo Issue. Cuando todas las fuentes requeridas son
HEALTHY/CURRENT, se cierra. Retries equivalentes no crean una tormenta de Issues.

## Health, deploy y Production Smoke

- GET /health y .github/workflows/production-deploy-observer.yml acreditan el checkout
  exacto desplegado. Un SHA distinto o ausencia de observación no es éxito.
- .github/workflows/production-smoke.yml es una señal funcional separada. Su
  diagnóstico sigue docs/AGENT-SMOKE-E2E.md.
- CI verde demuestra código, no producción. Observer y Smoke conservan identidad
  exact-SHA y su propia autoridad.
- Para diagnóstico de 5xx usar primero el bridge saneado de docs/AGENT-OPERATIONS.md;
  no copiar response bodies, cookies o logs crudos a Issues.

## Publicación y Distribution

La fuente durable para publicación es docs/AGENT-DISTRIBUTION.md y, para S4,
.github/workflows/s4-runtime-prepare.yml. Operations Health puede indicar que una señal
está stale/degradada, pero no habilita provider I/O, publicación externa, credenciales
ni cambios de fase.

Preparación reversible, pruebas y diagnósticos read-only pueden hacerse dentro de la
autoridad vigente. Publicación real conserva sus gates específicos.

## Storage, Media y Vault

Las fuentes canónicas son docs/AGENT-VAULT-UPLOADS.md y
docs/AGENT-MEDIA-CONNECTIONS.md.

Una readiness ausente o de otro release se trata como UNKNOWN. El runbook agregado no
inspecciona blobs, manifests privados, nombres de archivos, storage keys ni paths del
host. No prueba writes reales para ver si funciona.

## Recovery

Issue #311 y docs/PRODUCTION-RECOVERY.md son la única fuente operativa del formato y
procedimiento de recovery/restore. Este documento solo enlaza esa fuente.

Operations Health no ejecuta backup, decrypt, restore ni migraciones. Un merge,
ciphertext existente o drill histórico no demuestra recovery readiness actual. Restore
productivo destructivo sigue requiriendo incidente confirmado, evidencia válida y
aprobación explícita del propietario según el runbook de recovery.

## Handoff de incidente

El handoff automático o manual usa únicamente evidencia allowlisted:

- source;
- severity;
- state;
- freshness;
- SHA exacto;
- run ID o URL;
- conclusión terminal;
- fingerprint público del evaluador;
- referencia a este runbook y al runbook especializado aplicable.

Nunca incluir tokens, passwords, cookies, headers, request/response bodies, .env,
credenciales DB/provider, PII, paths privados, stack traces crudos, manifests privados
ni payloads de backup. Si una investigación necesita material sensible, permanece en
su canal/artefacto autorizado y el Issue conserva solo la referencia opaca permitida.

## Mantenimiento programado

El mantenimiento es manual, reversible y explícito. Antes de una ventana:

1. identificar el objetivo y el SHA/release afectados;
2. confirmar el gate especializado aplicable;
3. registrar rollback y criterio de salida;
4. detener únicamente los writers requeridos por el procedimiento;
5. reabrir servicio solo después de health/smoke coherentes.

Operations Health nunca ejecuta por inferencia:

- migraciones o SQL destructivo;
- restore productivo;
- SSH;
- chmod o chown;
- rotación/cambio de secretos;
- bulk writes;
- DNS o cambios de proveedor;
- compra/gasto;
- publicación externa;
- go-live.

Decir sigue, abrir un Issue o ver un check verde no sustituye una autorización operacional específica.

## Cierre y escalamiento

Una alerta solo se cierra cuando la misma fuente canónica vuelve a demostrar un estado
HEALTHY/CURRENT sobre el SHA exacto requerido. Si persiste UNKNOWN o STALE, se conserva
fail-closed y se registra la condición de desbloqueo.

Escalar al propietario antes de gasto, credenciales/datos reales, restore destructivo,
pérdida de evidencia, cambio de proveedor o go-live. Para recovery, escalar usando
docs/PRODUCTION-RECOVERY.md; no duplicar comandos sensibles aquí.
