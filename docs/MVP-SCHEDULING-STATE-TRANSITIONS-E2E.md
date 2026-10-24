# Regresiones E2E de fechas y entrega segura (GF581)

## Alcance autorizado

Decisión A auténtica del propietario en GrindFlow #571, hoja #581. Dos tests PHPUnit sintéticos, sin modificación del runtime, credenciales, hosting ni publicación real.

- `testUtcBoundariesAndDstTransitionsAreDeterministic`: `WeeklySlotCalculator` con reloj fijo. En UTC una programación de lunes 00:15 conserva el instante; una hora local inexistente en primavera se omite. **La hora repetida del otoño se selecciona en su primera ocurrencia bajo la implementación actual de PHP**. No se afirma que el código detecte o rechace esa ambigüedad, ni se cambia esa regla sin una decisión de producto posterior.
- `testRejectedOrAmbiguousStateNeverTriggersDuplicateExternalDelivery`: base de datos de prueba y tenant sintético. `ExternalPublicationLedger` recibe una función `send` local que simula rechazo o resultado ambiguo. El replay de la misma clave no vuelve a invocar el emisor; se limpia el fixture después. No hay HTTP hacia un proveedor.

## Verificación

CI requerido: `symfony-preview` (PHPUnit del proyecto, con base descartable) y `validate` sobre el SHA exacto; SonarCloud, CodeQL y revisión CodeRabbit FINAL según PLAN-AGENTES y D-062. El resultado de CI no acredita Hostinger ni publicación real.

## Límites y reversión

No se utilizan PII, credenciales, servicios externos, migraciones productivas ni nuevo esquema. La revisión de DST caracteriza el comportamiento actual, sin corregir de forma especulativa un contrato de negocio. Reversión: revert del único PR que añade estos dos archivos. La operación real sigue limitada por puertas humanas para publicación/go-live (#271/#453).
