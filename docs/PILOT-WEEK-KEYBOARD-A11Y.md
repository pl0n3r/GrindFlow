# Regresión de teclado y foco del selector semanal S5

## Autoridad y alcance

Hoja [#599](https://github.com/pl0n3r/GrindFlow/issues/599), epic [#344](https://github.com/pl0n3r/GrindFlow/issues/344), autorizada expresamente por el propietario en [#598](https://github.com/pl0n3r/GrindFlow/issues/598) mediante opción A y journal humano v2. La V3 solo reclama este documento y los dos archivos de pruebas. No cambia runtime, CSS, configuración, APIs, permisos, tratamiento de datos ni versión del producto.

## Qué se prueba

- Chromium real en preview Symfony descartable a **360 px y 820 px**.
- Contexto de identidad/organización sintética con acceso de lectura, sin secretos ni sesiones externas.
- Los botones «Semana anterior» y «Semana siguiente» tienen nombres accesibles. Tab y Shift+Tab recorren ambos, Enter y Espacio cambian semanas y el foco debe conservarse tras re-render.
- El control enfocado debe mostrar una señal de foco visible. El estado sin actividad se comunica como role=status; un HTTP 503 de la API sintética se comunica con role=alert, sin mostrar totales inválidos.
- El 503 no retiene el foco: se puede volver con teclado a la semana anterior. Sin desbordamiento horizontal y sin peticiones de escritura a rutas API de administración.
- Los datos semanales existen únicamente como interceptaciones Playwright de solicitudes GET. No acredita tráfico, piloto real, autorización de publicación, credenciales ni despliegue.

## Ejecutar en checkout de desarrollo

Primero iniciar el entorno Symfony desechable ya usado por CI. Desde la raíz del repositorio:

    python3 -m unittest -v tests.test_pilot_week_keyboard_a11y

En la carpeta symfony, tras instalar dependencias habituales del preview y Chromium:

    npm run test:e2e -- tests/e2e/pilot-week-keyboard-a11y.spec.mjs

La aceptación Python comprueba descubrimiento y wiring al job symfony-preview, pero **no ejecuta por sí sola el navegador** y no acredita AC-02. El workflow .github/workflows/grindflow-ci.yml ejecuta npm run test:e2e en symfony-preview, además del job browser y el check final validate. Exigir el resultado y log exact-HEAD.

## Evidencia y límites de fusión

- AC-01: pendiente de check de aceptación GitHub Actions sobre el HEAD final.
- AC-02: pendiente de logs Playwright Chromium / symfony-preview y job browser del HEAD final.
- AC-03: pendiente de validate, SonarCloud, CodeQL, Factory Policy terminal verdes y revisión CodeRabbit FINAL exact-HEAD conforme a D-062.
- Una pantalla accesible en CI no acredita piloto activo, proveedor configurado, despliegue Hostinger ni datos reales.

Si aparece una brecha en React/CSS, conservar la regresión roja sin parchear rutas fuera de la V3. Renovar contrato y claims ante Coordinación antes de tocar runtime. Reversión: revertir el squash de los tres archivos de pruebas/documentación; no hay migración, secreto ni efecto productivo.
