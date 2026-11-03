# Publicaciones salientes: doble llave y bitácora de entrega (#797)

**Estado:** contrato pre-live y regresión **con red simulada**. No existe aquí un endpoint, worker, middleware o adaptador productivo de publicación activado. No es una autorización para publicar, no implica aprobación jurídica ni acredita llamadas externas. Este Issue solo reclama esta guía y `symfony/tests/php/PublishDoubleKeyAuditTest.php`.

## Invariantes obligatorias antes de toda salida

1. **Permiso explícito del creador:** una concesión auténtica, vigente y no revocada, con alcance exacto a `tenant`, identidad del creador, `publication_id` y red de destino. `false`, dato faltante, erróneo, otro tenant/creador/red/publicación o `UNKNOWN` son rechazo. La capacidad para programar un borrador o aprobar un asset no equivale a autorizar la publicación saliente en otra red.
2. **Interruptor de emergencia #792 desactivado:** confirmar mediante fuente canónica que está explícitamente en modo **apagado** antes de la llamada externa. Encendido, ausente, no verificable o error de lectura bloquean **fail-closed**. La implementación/activación de #792 depende de su trabajo propio y no se considera instalada por este contrato.
3. **Bitácora de cada intento:** almacenar identidad del actor, fecha UTC, red canónica, publicación y resultado/motivo en un ledger de solo append, incluido rechazo, intento iniciado, éxito y error. Nunca guardar access tokens, texto/medios privados, cabeceras Authorization ni excepciones sin sanear. Si la escritura del intento previo falla, no llamar al proveedor; el fallo posterior al provider deja el intento pendiente/indeterminado para conciliación, sin reenviar ciegamente.
4. **No usar proveedores reales en CI:** `FakePublicationSender` únicamente cuenta envíos sintéticos y lanza errores simulados. Las redes admitidas en la referencia son `facebook_page` e `instagram_reels`, pero ninguna queda configurada o habilitada por estos tests.

La **doble llave es conjuntiva**: `permitido = permiso_específico_del_creador && emergencia_apagada`. No se deriva de permisos de operador/admin ni de estados de otra organización. El veto se verifica inmediatamente antes del I/O y tras cambios de consentimiento, de red o de emergency stop; cualquier resultado dudoso bloquea. La idempotencia de entrega y las carreras de concurrencia siguen bajo los contratos de Distribution en `docs/AGENT-DISTRIBUTION.md`.

## Contrato offline ejecutable

`PublishDoubleKeyAuditTest::test_criterio_principal` y cuatro regresiones negativas prueban el comportamiento del **puerto de referencia aislado que vive en el archivo de test**. La referencia audita con campos de allowlist, veta salidas sin ambas llaves y evita el I/O sintético cuando el log previo no está disponible. No instrumenta ni verifica aún el servicio de entrega en `symfony/src/`, que queda fuera de los claims actuales. Las identidades y el timestamp son fixtures inventados y deterministas.

    cd symfony
    php bin/phpunit tests/php/PublishDoubleKeyAuditTest.php

**AC-01:** `PublishDoubleKeyAuditTest::test_criterio_principal` cubre éxito simulado, pausa activa, `UNKNOWN`, concesión ausente, auditoría completa sin secretos. Pruebas adicionales cubren revocación, alcance cruzado, provider fallido y audit sink indisponible. **AC-02:** `CI Factory v1 / Validar` (`Factory CI reusable / Validar`) y CI canónico GrindFlow `validate` deben terminar en verde para el HEAD exacto. No falsificar un pase de PHPUnit desde la presencia del archivo; cotejar ejecución real en GitHub Actions.

## Integración futura y reversión

Para activar la puerta en producción hace falta una hoja nueva con reserva V3 sobre los archivos reales de entrada de Distribution, el almacenamiento persistente de consentimiento por publicación/red, la consulta autoritativa de #792 y el ledger append-only con transacciones/leases. Debe existir una regresión de integración que demuestre que **ninguna** ruta saliente (cola, reintento, API, worker) esquiva la puerta. Esos paths no están reclamados por #797; el merge de la guía/test no los conecta. No habilitar publicaciones live sin decisión expresa del dueño, puertas legales y pruebas de integración seguras.

Reversión: eliminar exclusivamente los dos archivos reclamados. No cambia esquemas, secretos, configuración, cron ni despliegues.
