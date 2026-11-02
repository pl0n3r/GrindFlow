# Data Subject Requests — integración segura pre-live (#799 / #814)

**Estado: PREPARACIÓN OFFLINE; no desplegado ni aprobado jurídicamente.**
La fuente de verdad operativa sigue siendo [#457](https://github.com/pl0n3r/GrindFlow/issues/457).
Este documento no concede go-live ni autoriza tratamiento de datos reales.

## Responsabilidades y límites

- El usuario autenticado puede **solicitar** exportación o supresión de sus datos; ningún campo `user_id`, `organization_id` o `role` proviene del cliente como autoridad.
- #814 implementa un backend Symfony aislado. La exportación preliminar solo incluye el registro de identidad propio (`id`, `display_name`, `email`), **no** todos los datos de todos los módulos. No afirmar exportación completa hasta inventariar las fuentes.
- La solicitud de supresión se registra `pending`, sin desactivar cuentas, borrar activos compartidos ni ejecutar operaciones destructivas. El `202 Accepted` confirma recepción, **no eliminación**.
- El registro guarda un seudónimo HMAC del actor, acción, estado y UTC; no incorpora correos, IP, nombres, payloads, credenciales ni ID de organización. La llave se mantiene en el servidor. Debe validarse la política de retención con asesoría jurídica.
- La versión offline de #814 limita estos endpoints a `APP_ENV=test`. En `dev`/`prod` fallan cerrado con `404`; nunca asumir que la API está disponible en producción tras fusionar código.
- No hay plazos numéricos garantizados: hasta completar la integración de canales y la decisión del dueño, se comunica **«mejor esfuerzo, sin SLA»**.

## Contrato HTTP propuesto para fixtures sintéticos

| Método y ruta | Respuesta esperada | Condiciones |
| --- | --- | --- |
| `GET /api/admin/privacy/data-subject-requests/csrf` | `200` y token privado | Sesión autenticada en `APP_ENV=test` |
| `POST /api/admin/privacy/data-subject-requests/export` | `200` JSON adjunto privado | Sesión, `X-CSRF-Token`, JSON exacto `{"confirm":true}` |
| `POST /api/admin/privacy/data-subject-requests/deletion` | `202` estado `pending` | Sesión, `X-CSRF-Token`, confirmación explícita |
| Cualquier mutación sin sesión, sin CSRF o con IDs inyectados | `401`, `403` o `422` | Sin efectos secundarios sobre la identidad ajena |
| Las mismas rutas con `APP_ENV` distinto de `test` | `404` | Deshabilitadas por diseño en preparación pre-live |

Los nombres de rutas son **provisionales** y están sujetos a la ratificación del contrato de #814 al integrar bajo V3.

## Prueba de aceptación principal

La ruta reclamada por #799 es `symfony/tests/php/DataSubjectRequestsTest.php` y su método público `test_criterio_principal`.
El test solo usa cuentas y organizaciones `@example.test` con MariaDB descartable, verifica autenticación, CSRF, aislamiento de tenant, bitácora sin payload personal, no borrado y reintentos idempotentes. Requiere el código/migración de #814.

El texto de #799 contiene el identificador generado `Datasubjectrequeststest.Tests::test_criterio_principal`, que no coincide con la convención de clases PHP del repositorio. Antes del PR, confirmar con el validador efectivo que reconoce `GrindFlow\\Tests\\DataSubjectRequestsTest::test_criterio_principal` sin modificar compuertas ni inventar un resultado verde.

## Dependencias y operación

1. #814: primero reserva V3 independiente, backend y migración aditiva, CI `symfony-preview` y `validate` en HEAD exacto, única revisión CodeRabbit FINAL, squash protegido.
2. #799: obtener **su propia** reserva V3 para estas dos rutas, integrar la prueba y el runbook, ejecutar aceptación en Symfony/MariaDB aislados y validar exact-HEAD antes de squash. No usar la reserva de #814 ni tocar sus rutas.
3. ControlBot [#1358](https://github.com/pl0n3r/ControlBot/issues/1358) está cerrado y entregó **un contrato documentado** de canales por proyecto, no la integración de canales ni un SLA contractual. Falta la hoja de integración consumidora; no inventar correo ni responsable.
4. #457: completar responsables, bases, consentimientos, retención, solicitudes reales y dictamen jurídico externo. No desplegar antes de autorización expresa y backup aprobado.

## Criterios de no-go y reversión

**No-go** cuando falten autoridad V3, CI exact-HEAD, aislamiento entre tenants, revisión jurídica, política de retención, canal de soporte autorizado, copia de seguridad o autorización de despliegue. El squash de código es reversible, pero NO autoriza una reversión destructiva de bitácoras o migraciones. Ninguna prueba de este paquete contacta personas, redes externas, usuarios productivos ni servicios de pago.
