# GF795 — Simulación de recorrido del piloto (sin proveedores externos)

**Estado:** prueba local automatizada; no constituye lanzamiento, publicación social real ni aprobación del piloto humano #271.

## Entorno y límites

- Symfony, WebTestCase y MariaDB desechable con las migraciones correspondientes.
- Identidad sintética @example.test, contraseña efímera de test, organización y asset de prueba. La alta se crea mediante fixture local, **no** se afirma que se completó un registro público externo.
- PNG sintético en memoria (1×1), escrito a un temporal que se elimina al finalizar la subida.
- El transporte de Facebook Page inyectado falla de forma explícita si recibe una llamada: jamás usa URL, token, persona o cuenta real.
- La publicación sintética usa ExternalPublicationLedger con callback in-memory y resultado ficticio `SIMULATED-NOT-EXTERNAL-GF795`; el estado `published` del ledger representa exclusivamente el ensayo, **no** una publicación real.
- No se usan Hostinger, producción, conexiones a redes, credenciales ajenas ni datos personales.

## Tabla de evidencias por ejecución

El test `PilotJourneySimulationTest::test_criterio_principal` imprime por cada fase `GF795-SIM <paso>=OK|FALLA|NO_EJECUTADO`. La tabla representa los controles exigidos; cada ejecución real determina el resultado.

| Paso | Evidencia del test | Alcance |
| --- | --- | --- |
| registro | Inserta usuario y organización de prueba con membresía | Alta por fixture sintética |
| ingreso | Login web y contexto de permisos | Sesión propia |
| subida | POST multipart PNG, 201 y SHA-256 verificado en Vault | Archivo sintético |
| clasificacion | POST /usage con token CSRF; pasa a needs_review | No publica |
| revision | PUT aprobación explícita; preview confirma requisito | No programa |
| autorizacion | PUT consentimiento independiente del distribuidor | No publica |
| programacion | POST /schedules crea exactamente un draft | No publica |
| publicacion_simulada | Callback local del ledger retorna ID sintético; reentrega idempotente y cero llamadas de transporte | Solo ledger local |

Las fases no recorridas por un error muestran `NO_EJECUTADO`; la que falla se marca `FALLA`, de modo que no se inventan fases exitosas.

## Reproducción

```bash
cd symfony
APP_ENV=test ./vendor/bin/simple-phpunit tests/php/PilotJourneySimulationTest.php --filter test_criterio_principal
```

Utilizar una MariaDB descartable con esquema migrado; comprobar `GF795-SIM` en stdout y un resultado PHPUnit terminal correcto. Para aceptar la entrega también se requieren `Factory CI reusable / Validar`, `GrindFlow CI / validate`, `symfony-preview`, SonarCloud/CodeQL y revisión CodeRabbit FINAL sobre el SHA exacto, conforme a `PLAN-AGENTES.md`.

## Interpretación y reversión

La etiqueta `published` en `gf_external_publication_attempts` pertenece solo a una fila aislada en la base de test y nunca representa una publicación externa. Para revertir el cambio basta revertir el squash de estos dos archivos. El piloto real de #271 requiere autorización humana y validaciones separadas.
