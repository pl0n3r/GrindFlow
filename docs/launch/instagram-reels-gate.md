# Instagram Reels — puerta de privacidad y autorización (GF #798)

**Estado: deshabilitado por defecto. No habilitar para pilotos ni producción.**

La configuración `InstagramReelConfiguration` arranca con `enabled = false`.
En Symfony, `grindflow_instagram_enabled_default: 'false'` es el valor por defecto de
`GRINDFLOW_INSTAGRAM_REELS_ENABLED`. Con el interruptor apagado,
`InstagramReelPublicationService::publish()` consulta al proveedor y rechaza
con error `configuration` antes de crear el ledger de publicación, encolar o
contactar Meta. El test
`symfony/tests/php/InstagramReelsDisabledTest.php::Instagramreelsdisabledtest.Tests::test_criterio_principal`
verifica el rechazo, cero operaciones en Doctrine y cero llamadas al transporte.

## Habilitación: solo mediante decisión explícita

No establecer `GRINDFLOW_INSTAGRAM_REELS_ENABLED=true` hasta que **ambos**
requisitos estén documentados y verificados:

1. Visto bueno jurídico específico y verificable para el tratamiento de datos,
   uso de Instagram Reels y publicación mediante Meta; registrar la evidencia
   correspondiente al issue [#611](https://github.com/pl0n3r/GrindFlow/issues/611).
   **Cerrar #611 administrativamente no equivale a aprobación del abogado.**
2. Decisión humana explícita del propietario para el **live** y sus puertas
   operativas, pruebas y autorizaciones correspondientes.

La existencia de credenciales, tokens, endpoints, un PR fusionado o un CI verde
**no autoriza** activar Reels. No subir secretos ni contenido de personas reales
como parte de este cambio. La activación futura requiere una tarea/decisión
separada, configuración de producción autorizada y verificación posterior.

## Pruebas y reversión

Ejecutar el test principal y la suite PHPUnit/CI del HEAD exacto; la regresión
falla si el interruptor deja pasar el envío hacia Meta o accede al ledger.
Revertir el PR de #798 retira esta prueba y documentación; **no cambia** el
valor existente `false` ni habilita Instagram Reels.
