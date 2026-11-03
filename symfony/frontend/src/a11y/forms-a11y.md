# GF344 · Accesibilidad de formularios, filtros y paginación

**Issue:** #670 · Contrato de aceptación: `validate` y `symfony-preview` en el mismo SHA.

## Evidencia ejecutable y límites

Esta hoja cubre las superficies que existen hoy en `symfony/`, sin introducir
rutas de producción, usuarios reales, credenciales ni cambios de runtime.
Las pruebas PHP en `symfony/tests/php/FormsAccessibilityTest.php` forman parte
del `symfony/phpunit.xml.dist` del preview y prueban el HTML real de /login,
/forgot-password y /recover-password, los atributos de error y las plantillas
Twig que generan nombres accesibles de organizaciones.

El archivo `symfony/tests/Browser/forms-a11y.spec.ts` contiene escenarios
Chromium para 360/390/820 px: enfoque en el primer campo inválido, etiqueta
accesible, mensaje de error asociado y ausencia de overflow. **Advertencia
explícita:** `symfony/playwright.config.mjs` configura
`testDir: './tests/e2e'`, por lo que el nuevo archivo Browser **no queda
descubierto automáticamente** en `npm run test:e2e` ni en `symfony-preview`.
No marcar los casos Browser como ejecutados sin un runner que incluya esta ruta.
Cualquier integración del discovery requiere otra hoja con claim de configuración
según PLAN-AGENTES; no modificarla desde #670.

Los filtros y paginación del panel React tienen regresiones existentes como
`symfony/tests/e2e/pilot-week-keyboard-a11y.spec.mjs`, que comprueban
teclado, semanas anterior/siguiente, foco persistente y estados 503/empty.
`OrganizationSelectorAccessibilityTest.php` verifica nombre/rol/estado
actual en el selector autenticado con identidades descartables. Esas pruebas
son reutilizadas, no duplicadas.

## Mapa de criterios

- **AC-01:** `validate` comprueba la integración y los contratos base.
- **AC-02:** `symfony-preview` ejecuta PHP/Symfony, incluyendo
  `FormsAccessibilityTest.php`, además de sus pruebas Chromium existentes.
- **Regresión negativa:** quitar las etiquetas `for`, los atributos
  `aria-describedby`, el error con `role="alert"`, el token acotado
  o los nombres de selector hace fallar las aserciones correspondientes.
- **Privacidad:** se usan valores `synthetic-invalid@example.test`, sin
  navegación a proveedores externos, efectos persistentes de negocio ni PII real.

## Reversión

Revertir el squash elimina únicamente estos tres archivos de pruebas y
documentación. La evidencia de código no equivale a despliegue ni a una auditoría
WCAG integral; los módulos no cubiertos no se declaran accesibles.
