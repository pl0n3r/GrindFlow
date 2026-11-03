# Aceptación responsive móvil — GrindFlow #671

## Alcance y evidencias

La suite `symfony/tests/Browser/responsive-mobile.spec.ts` protege exclusivamente las pantallas públicas anónimas del MVP: `/`, `/preview` y `/login`. Ejecuta una matriz de **3 rutas × 3 anchos (360, 390, 768 px)** y tres pruebas de regresión adversaria por ancho (**12 tests Playwright**). Las capturas de pantallas públicas se guardan como artefactos por prueba; nunca incluyen credenciales, cuentas ni contenido de tenant.

El detector comprueba que no haya desplazamiento horizontal en documento/cuerpo, que los controles visibles no queden fuera del viewport y que títulos, etiquetas o botones con recorte CSS no pierdan texto. Las pruebas sintéticas inyectan desbordamiento, un encabezado cortado y un botón fuera del ancho; deben producir defectos y fallar si se rompe la detección.

## Límites de seguridad

Se requiere `S0_BASE_URL=http://127.0.0.1:<puerto>`, HTTP local con puerto explícito. El navegador bloquea service workers y cierra WebSockets; toda solicitud no GET/HEAD o fuera del mismo origen se aborta. Cualquier acceso a `/api/` o `/admin/` recibe un 403 sintético. No se usan credenciales, servicios externos ni Hostinger; no se realizan acciones productivas.

## Ejecución y criterios

Con el Symfony preview local disponible, desde `symfony/`:

```bash
S0_BASE_URL=http://127.0.0.1:8765 npx playwright test tests/Browser/responsive-mobile.spec.ts
```

- **AC-01:** check `validate` terminal `success` sobre el SHA exacto del PR.
- **AC-02:** check `symfony-preview` terminal `success` incluyendo el descubrimiento de esta suite (configuración `testDir: './tests'`).
- Ante error, se corrige en las rutas reclamadas y se repiten los checks del nuevo HEAD, sin aceptar un resultado de un SHA anterior.
- La reversión es el revert del squash correspondiente a #671.

No se confunde esta especificación con evidencia de ejecución: solo GitHub Actions del HEAD final puede acreditar los gates CI.
