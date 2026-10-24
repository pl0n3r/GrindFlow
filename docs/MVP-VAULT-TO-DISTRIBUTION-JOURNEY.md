# QA E2E integrado · Vault → revisión de contenido → Scheduling → Distribution

**Hoja:** [GrindFlow #566](https://github.com/pl0n3r/GrindFlow/issues/566) · roadmap [#342](https://github.com/pl0n3r/GrindFlow/issues/342) · dirección aprobada en [#565](https://github.com/pl0n3r/GrindFlow/issues/565#issuecomment-6385701166), opción A del OWNER.

## Objetivo y superficie real

`symfony/tests/php/MvpVaultToDistributionJourneyTest.php` prueba un **mismo ID de asset creado por HTTP en Vault**; lo clasifica y aprueba mediante los endpoints de contenido/revisión (la capacidad de preparación existente, sin inventar una ruta `/composer`), exige autorización separada de Distribution y crea un borrador local de Scheduling. La secuencia verifica IDs y tenant a través de HTTP, MariaDB de prueba y el ledger de eventos.

A diferencia de `MvpScheduleToDistributionE2eTest.php` (#557), el asset propio **no se inserta directamente en base de datos**: llega por `POST /api/admin/vault` con PNG sintético y CSRF de la sesión; la ruta de revisión de contenido es una compuerta distinta de la autorización de distribución. El formulario de login y la elección de organización son los reales del `WebTestCase`; ningún token personal real se introduce.

## Contrato de pruebas

- **AC-01:** `testFullAssetJourneyPreservesTenantAndExplicitAuthorization` exige 201 de Vault, hash SHA-256 en base de datos de prueba, clasificación `needs_review`, aprobación explícita por `content-reviews/{asset}`, bloqueo previo de Distribution, `PUT /distribution-authorizations/{asset}` con CSRF y finalmente 201 de `POST /schedules`. Los ID del asset/organización coinciden, el borrador queda `draft` y `publishes=false`.
- **AC-02:** `testCrossTenantOrUnauthorizedJourneyDoesNotCallTransport` comprueba 401 sin sesión, 403 sin CSRF o con rol revocado, 404 para recurso extranjero, y 409 para un asset sin revisión/autorización. Requiere cero eventos de decisión, cero borradores y cero intentos externos tras los negativos.
- **AC-03:** `symfony-preview` ejecuta PHPUnit desde `phpunit.xml.dist` con MariaDB desechable y ambos métodos presentes; `validate`, SonarCloud, CodeQL y Factory Policy se inspeccionan sobre SHA final. CodeRabbit solo se solicita **una vez al final con CI verde**, no en commits de preparación.

El proveedor Facebook Page queda sustituido por un `FacebookPageTransport` fake que contabiliza invocaciones y lanza excepción ante cualquier intento de `postFeed` o `postPhoto`. Los dos métodos exigen `calls===0`, y no permiten registrar publicaciones externas. En estas pruebas no hay endpoints de red salientes intencionales ni proveedor efectivo.

## Ejecución y límites

Comando técnico en entorno Symfony con dependencias y MariaDB de prueba migrada:

```bash
cd symfony
php vendor/bin/simple-phpunit -c phpunit.xml.dist --filter MvpVaultToDistributionJourneyTest
```

Fuente de verificación de CI: job `symfony-preview` de GitHub Actions sobre el SHA del PR, seguido del agregado `validate`. Esta documentación no equivale a un PASS hasta que existan run/jobs terminales.

**No autorizado:** tocar controlador/servicios, migraciones, sesiones reales, secretos, almacenamiento S3, integraciones Meta, `public_html`, versión, release, configuración o datos productivos. El Issue [#146](https://github.com/pl0n3r/GrindFlow/issues/146) conserva Direct Upload sin configurar y no se declara HEALTH integral GREEN. El squash de la hoja es reversible sin modificar runtime.
