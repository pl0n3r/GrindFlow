# Interruptor de emergencia — contrato seguro de construcción (#792)

**Estado (2 nov 2026): referencia de pruebas offline solamente. NO implementado en el runtime de Symfony ni en producción.** Este documento y `symfony/tests/php/KillSwitchTest.php` son las únicas rutas reclamadas por la reserva V3 del issue. No configuran ni conectan ninguna integración real, no alteran tokens de clientes y no son una aprobación para publicar.

## Operación esperada

- **Parada global:** un administrador autorizado activa el estado `STOP`. Todas las salidas hacia redes sociales, los trabajos programados, los reintentos y los workers deben consultar estado autoritativo inmediatamente antes de cada efecto externo y denegar si está en `STOP`, si falta o si el valor es `UNKNOWN`. No confiar en un estado cacheado ni en una señal de éxito de un job previo.
- **Revocación ordenada:** fijar `STOP` de forma atómica y duradera **antes** de invalidar sesiones y credenciales de acceso. Revocar tokens y sesiones de todas las organizaciones con una operación idempotente y reanudable. Una revocación parcial no debe volver a habilitar envíos ni impedir reintentos. No incluir tokens en errores, eventos, auditorías o PRs.
- **Bitácora:** registrar antes de cada intento de activación/restauración el actor autenticado, instante UTC, acción, motivo saneado y resultado. El log debe ser append-only y resistente a errores parciales. Si la persistencia de la orden de restaurar falla, conservar `STOP`; no interpretar ausencia de auditoría como permiso. Denegaciones y reintentos deben poder rastrearse.
- **Restauración:** exclusivamente mediante una acción nueva y explícita de administrador, con motivo y auditoría persistente. No restaurar automáticamente por finalizar un job, expirar un timeout, recuperar una API, reiniciar el proceso ni después de una revocación incompleta. Si no se puede corroborar el estado anterior, fallar cerrado.
- **Concurrencia:** el diseño productivo necesitará estado compartido, comparación-atómica/CAS, fencing de workers, propagación con invalidación, y comprobación de la puerta justo antes de la llamada externa. La reanudación no debe deshacer una parada posterior por carrera.

La parada de GrindFlow **no sustituye** el interruptor global de agentes de Factory #767. Ambos son límites separados: uno rige a los agentes y el otro debe regir publicación, scheduler y accesos de GrindFlow.

## Qué valida este slice de prueba

El archivo `symfony/tests/php/KillSwitchTest.php` contiene la clase exacta `Killswitchtest\Tests` y el método `test_criterio_principal`, más cuatro regresiones. Usa exclusivamente un servicio **de referencia privado al archivo de test** y puertos en memoria, sin dependencias de red o proveedores:

| Escenario sintético | Comportamiento requerido |
| --- | --- |
| Activación desde estado conocido | Veta publicación y scheduler en `tenant_a` y `tenant_b`, invalida sus sesiones y tokens falsos; audita actor/tiempo/motivo |
| Estado `UNKNOWN` y restauración no administradora | Deniega envíos y tareas; no reanuda sin autoridad |
| Fallo parcial de revocación | Conserva veto, impide restauración prematura y permite reintento idempotente |
| Auditoría no disponible | Conserva veto; no declara éxito ni utiliza recursos externos |
| Credenciales ficticias, identidad y motivo inválidos | Evita registrar tokens, cabeceras o payloads y comprueba alcance global sin exponer datos cruzados |

Prueba acotada, desde `symfony/`:

```sh
php bin/phpunit tests/php/KillSwitchTest.php --filter test_criterio_principal
php bin/phpunit tests/php/KillSwitchTest.php
```

**AC-01:** los tests deben ejecutarse en el CI real; un método presente o `php -l` no equivalen a PHPUnit verde. **AC-02:** el check `Factory CI reusable / Validar` y `GrindFlow CI / validate` deben finalizar en `success` sobre el commit HEAD exacto del PR. Las verificaciones SonarCloud, CodeQL y la revisión CodeRabbit FINAL se evalúan bajo las reglas actuales de Factory; no solicitar CodeRabbit antes de CI verde. La referencia no acredita un despliegue, revocación real ni E2E productivo.

## Integración pendiente bajo reservas independientes

La parte operacional pedida en #792 **no se puede afirmar implementada** por solo estos dos archivos: requiere otra hoja con aceptación y claims específicos sobre los puntos de salida de Distribution, planificador/cola/reintentos, entidad de estado durable, servicio de identidad/refresh tokens, auditoría y rutas administrativas protegidas. Sus pruebas deberán inyectar fallos de persistencia, carreras y workers antiguos para demostrar que **ningún** publisher o tarea supera `STOP`. La coordinación debe registrar la hoja y reserva V3 antes de editar rutas externas a #792.

La implementación real necesita evaluación de sesión activa/refresh token, minimización de datos personales y decisión explícita de live; esta referencia no toca cron, Hostinger, secrets ni datos de clientes. No activar publicaciones ni programar trabajo real a partir de este documento.

## Reversión

Revertir el squash que añade estas dos rutas elimina únicamente la referencia y documentación. No modifica esquema, procesos, hosting, cuentas ni tokens. El interruptor productivo deberá tener su propia reversión y pruebas antes de activarse.
