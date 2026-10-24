# Regresión HTTP 500 del kernel Symfony · GF343_KERNEL_500_HTTP_E2E_V1

## Autoridad y objetivo

Hoja [#569](https://github.com/pl0n3r/GrindFlow/issues/569), derivada del roadmap [#343](https://github.com/pl0n3r/GrindFlow/issues/343). La puerta product-direction [#568](https://github.com/pl0n3r/GrindFlow/issues/568) tiene una opción A autenticada: **solo** un test HTTP offline reversible en `APP_ENV=test`, sin tráfico productivo ni persistencia de datos personales.

El contrato de [#560](https://github.com/pl0n3r/GrindFlow/issues/560) verificaba `X-Request-Id` sobre una `Response(500)` creada directamente. No ejercitaba la ruta de excepción de `HttpKernel`. Este slice cierra exclusivamente esa laguna de QA y **no** demuestra el pipeline de observabilidad en producción.

## Qué ejecuta el test

`symfony/tests/php/RequestIdKernelExceptionE2eTest.php` extiende `WebTestCase` y utiliza el `KernelBrowser` real de Symfony:

1. Arranca el kernel `test` con `APP_DEBUG=0`.
2. Agrega un listener temporal al evento `kernel.controller` que lanza una `RuntimeException` ficticia **solo** para una petición principal a la ruta existente `/health`. No instala rutas, servicios ni controladores permanentes.
3. Deja que `HttpKernel` y sus event subscribers procesen la excepción y construyan la respuesta HTTP 500 auténtica.
4. Comprueba que la cabecera `X-Request-Id` sigue `req_[0-9a-f]{24}`, fue acuñada por el servidor y coincide con el atributo confiable de esa petición. Un valor del cliente no puede reemplazarla.
5. Comprueba en el cuerpo y cabeceras de la respuesta que no figuren texto de excepción, traza, `Authorization`, email ni tokens **sintéticos**.

La prueba falla si no ocurre la excepción, si el kernel revela cadenas sintéticas o si se pierde la correlación. Los fixtures no son contraseñas, cuentas, identidades ni secretos reales.

## Cómo verificar

Dentro del CI existente, el job **`symfony-preview`** instala dependencias de prueba y ejecuta PHPUnit bajo el proyecto Symfony. Para repetir el subconjunto en un entorno descartable con dependencias ya instaladas:

```bash
cd symfony
APP_ENV=test APP_DEBUG=0 vendor/bin/phpunit --configuration phpunit.xml.dist tests/php/RequestIdKernelExceptionE2eTest.php
```

El ejecutor puede utilizar el wrapper de PHPUnit definido por el workflow si la instalación emplea Symfony PHPUnit Bridge; deben constar los dos métodos:

- `testKernel500ReturnsCorrelatedServerGeneratedRequestId`
- `testKernel500DoesNotExposeExceptionOrSyntheticSecrets`

La finalización de `symfony-preview` **y** `validate` en el **mismo SHA** es obligatoria para aceptar la hoja. CodeQL y SonarCloud se investigan sin ocultar findings graves; CodeRabbit se invoca una única vez en revisión FINAL y con CI verde. El check verde no constituye `VALIDATED_IN_PRODUCTION`.

## Superficie y reversión

Solo se incorporan el archivo de pruebas y este documento. No hay cambios en `RequestIdSubscriber.php`, controllers, APIs, proveedores, logging, DB, configuración de seguridad, `datos.yml`, credenciales ni despliegue. El handler temporal existe únicamente dentro de PHPUnit. Para revertir una fusión, revertir el squash del PR y volver a ejecutar `symfony-preview` y `validate`. Mantener `#146` (storage externo) y go-live como puertas independientes.
