# MVP · Viewports responsive con Chromium real (#533)

Hoja 2 de #530, posterior a #532 / PR #534. Contrato de navegador real sobre DOM y fixtures descartables; no valida la interfaz productiva autenticada ni tráfico de producción.

## AC-01 — layout y overflow físico

Chromium construye un iframe de **390×844** y otro de **768×900** y verifica innerWidth/innerHeight, scrollWidth, bordes de las tarjetas y el cambio calculado de una a dos columnas. El iframe conserva el viewport exacto independientemente del ancho mínimo de la ventana Chrome externa. Un elemento adversario de 1600 px debe fallar con el resultado DOM «FAIL: horizontal overflow» en ambos tamaños.

## AC-02 — paginación y estados

En ambos tamaños se verifican los nodos loading/empty/error visibles, regiones de anuncio accesibles, foco real del botón, geometría y click nativo que incrementa la página. Los casos negativos quitan el handler de paginación o el canal de anuncio real de loading/empty/error y deben producir errores DOM exactos, no pasar por coincidencias de cadenas CSS.

## AC-03 — CI y límites de confianza

El script scripts/browser-smoke.sh ejecuta primero scripts/browser-workflow.sh, luego las pruebas de #532 y finalmente python3 -m unittest tests/test_mvp_responsive_viewports.py -v. Los jobs browser y real-stack ya usan ese script; set -euo pipefail propaga el resultado, sin modificar el workflow de #504.

Para ejecutar con Chrome local:

    GRINDFLOW_BROWSER_CHROME="$(command -v google-chrome || command -v chromium)" python3 -m unittest tests/test_mvp_responsive_viewports.py -v

Sin Chrome real no hay skip verde. Cada test usa perfil y archivo file:// temporales, sin cuentas, secretos, servidores externos, producción, datos reales ni pagos. Estos casos certifican solo el comportamiento de la fixture en Chromium; los recorridos de producto autenticado tienen evidencia propia. Reversión: revertir el squash de #533 sin tocar la cobertura #532.
