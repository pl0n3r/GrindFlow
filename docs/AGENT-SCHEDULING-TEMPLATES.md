# Scheduling V1 — plantillas, calendarios y reintentos seguros

Hoja build-ahead de [GrindFlow #429](https://github.com/pl0n3r/GrindFlow/issues/429), derivada de las decisiones #59, #60 y #62. Código puro para Symfony. **Sin** persistencia, credenciales, llamadas de proveedores, publicación, cron, migraciones ni activación. El Scheduler Symfony consumirá estas reglas cuando sus gates operativos estén satisfechos.

## Plantillas y campañas

`CalendarTemplate::apply(pattern, target)` vincula una plantilla a una sola cuenta, campaña o período del **mismo tenant**. Valida identificadores, zona IANA, hora local, weekdays ISO 1–7, frecuencia y fechas reales. Rechaza ámbito cruzado, tenant distinto, patrón malformado y fechas imposibles; no incluye texto externo ni PII en las excepciones. El resultado es solo una descripción normalizada, no una programación ejecutada.

`CampaignCalendar::define(campaign, pattern, ownership)` asocia una regla a la campaña y su colección explícita. `ownership` es un snapshot **obligatorio** de catálogo validado en el servidor (`account_id`, `account_tenant_id` y mapa completo `collection_tenant_by_id`), inyectado por el consumidor futuro; nunca se debe construir a partir de IDs enviados por el cliente. Sin ese snapshot o cuando una cuenta/colección pertenezca a otro tenant, el método falla cerrado. Cada resultado es independiente de otras campañas, incluso cuando comparten cuenta. IDs duplicados, colecciones vacías y tenants ajenos se rechazan. `activation_allowed=false` permanece explícito: no programa ni publica.

## Recuperación

`RetryClassifier::classify(failure)` retorna `decision` (`retry_safe | retry_never | needs_human`) y `reason` de un catálogo controlado, sin payload del proveedor.

- Estado externo ambiguo, timeout **después** del envío, estado `in_flight`, evidencia inválida y error desconocido: `retry_never`.
- Falla de autorización: `needs_human`; nunca se reintenta automáticamente.
- Solo `rate_limited` o `transient_before_send` *confirmados*, con idempotencia demostrada por el consumidor, idempotency key válida e intentos restantes: `retry_safe`.

El módulo **no demuestra** idempotencia por sí mismo. Antes de ejecutar un retry real, el consumidor debe revalidar identidad, estado y locks de entrega, verificar que el proveedor garantiza el mismo efecto con la clave, y persistir presupuesto e historial. Ninguna respuesta aquí equivale a evidencia de provider o de producción.

## Validación y límites

`php symfony/tests/php/SchedulingTemplatesRetryTest.php` ejecuta cuatro grupos de regresiones; `python3 -m unittest tests/test_scheduling_templates_retry_contract.py -v` los vincula a AC-01..04 en GitHub Actions. Se dejan intactos README, versionado, lockfiles, Scheduler/Distribution existente y paths de otras reservas. La integración futura requiere revisión QA/seguridad, checks exact-HEAD y las puertas de fase.
