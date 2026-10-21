# WCAG AA calculado en Chromium — GrindFlow MVP

Implementación QA de la autorización del dueño en [#542](https://github.com/pl0n3r/GrindFlow/issues/542); contrato ejecutable y V3 en #545.

El job de navegador existente (`scripts/browser-smoke.sh`) ejecuta la suite Python con Chrome/Chromium real. El fixture HTML se crea en archivo temporal (`file://`), sin red externa ni credenciales. JavaScript obtiene los colores finales mediante `getComputedStyle`, compone RGBA sobre fondos de ancestros y calcula luminancia sRGB y razón de contraste.

Umbrales: texto normal 4.5:1, texto grande 3:1 y bordes esenciales de controles 3:1. Se verifica éxito y fallos inyectados sobre el texto, borde y alfa de la tipografía. Los elementos deshabilitados y ocultos se excluyen, mientras fondos con imagen u opacidad de un elemento habilitado no soportados fallan cerrado, sin PASS artificial.

**Límite:** el DOM es sintético y representativo de controles MVP, NO una auditoría integral de pantallas reales ni acreditación de producción. El teclado/foco y responsive pertenecen a #532/#533. La aceptación exige `browser` realmente ejecutado y `validate` terminal sobre el SHA exacto; los jobs `skipped` no satisfacen AC-03.

Reversión: squash de las tres rutas de #545, sin migración ni datos persistentes.
