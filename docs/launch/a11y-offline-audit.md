# GrindFlow: auditoría estática de accesibilidad WCAG 2.2 AA

**Estado: PREPARACIÓN OFFLINE, NO ENSAYADO en producción.** Issue [#653](https://github.com/pl0n3r/GrindFlow/issues/653), roadmap [#2](https://github.com/pl0n3r/GrindFlow/issues/2). Fuente: `main@313e25153db91a78d516fd82ca20d5c1e9771073`, 27-oct-2026. Una lectura estática NO acredita conformidad WCAG 2.2 AA ni go-live.

## Alcance y método

Lectura de solo código de las pantallas Twig y React del viaje **login, subir material, programar y preparar/publicar una entrega**. Se revisaron etiquetas, orden de foco esperable, contraste no medido y mensajes de error. **OBSERVADO EN CÓDIGO** no significa probado en navegador; **UNKNOWN** indica ausencia de evidencia; **NO ENSAYADO** significa sin recorrido real; **PROPUESTA** indica una futura prueba/corrección, no implementada. No se usaron cuentas, archivos reales, producción ni secretos.

## Evidencia de plantillas y componentes

| Etapa | Evidencia por archivo y línea | Lectura y límite |
| --- | --- | --- |
| Login | `symfony/templates/base.html.twig:11-13`; `symfony/templates/identity/login.html.twig:5-22` | Skip link a `main#contenido`, `label` para email/contraseña; error con `role="alert"`, `aria-invalid` y `aria-describedby`: OBSERVADO EN CÓDIGO |
| Foco del login | `symfony/tests/e2e/keyboard-accessibility.spec.mjs:4-28`; `symfony/tests/e2e/login-accessibility.spec.mjs:4-24` | Aserciones Playwright de foco/anillo y errores en viewport 360/820: existen en fuente, pero ejecución real del journey desplegado: NO ENSAYADO |
| Subir material | `symfony/frontend/admin/VaultPanel.tsx:703-769` | Input de archivo con label, alertas por rechazo y progreso `role="status"` con `progress aria-label`: OBSERVADO EN CÓDIGO |
| Programar | `symfony/frontend/admin/ScheduleDraftPanel.tsx:568-594` | Dos selectores etiquetados; `role="status"` para feedback y `role="alert"` para error: OBSERVADO EN CÓDIGO |
| Preparar/publicar | `symfony/frontend/admin/ScheduleDraftPanel.tsx:636-715`; `symfony/frontend/admin/ManualHandoffPanel.tsx:156-208` | Botones condicionales de entrega; la cola manual de solo lectura no certifica publicación externa: OBSERVADO EN CÓDIGO |
| Contraste, móvil y publicación real | Artefacto autenticado de producción: UNKNOWN | Contraste completo, foco tras error, reflow a 320 px, publicación por teclado: NO ENSAYADO |

## Hallazgos y propuestas WCAG 2.2 AA

| Hallazgo | Referencia AA | Fuente exacta y estado | Corrección pequeña propuesta |
| --- | --- | --- | --- |
| A11Y-01 Etiquetas | 1.3.1, 3.3.2 | `symfony/templates/identity/login.html.twig:14-20`; `VaultPanel.tsx:703-706`; `ScheduleDraftPanel.tsx:568-584`: etiquetas observables | PROPUESTA: regresión DOM para asegurar asociación dinámica al cambiar etapa |
| A11Y-02 Foco | 2.4.3, 2.4.7, 2.4.11 | `symfony/templates/base.html.twig:11-13` y `keyboard-accessibility.spec.mjs:4-28`: skip link y pruebas; foco tras error de subida/publicación UNKNOWN | PROPUESTA: ensayar Tab, Shift+Tab, Escape, foco tras error y ausencia de obstáculos |
| A11Y-03 Contraste y reflow | 1.4.3, 1.4.10, 1.4.11 | No hay ratio cromático por estado o ensayo completo sobre artefacto desplegado: NO ENSAYADO | PROPUESTA: medir AA normal/error/loading a 320 CSS px/400 % zoom, con captura y SHA |
| A11Y-04 Errores/estado | 3.3.1, 4.1.3 | `login.html.twig:10-20`, `VaultPanel.tsx:734-769`, `ScheduleDraftPanel.tsx:593-594`: alerts/status en código | PROPUESTA: comprobar anuncio y foco con lector de pantalla tras errores 401/403/409 |

**Correcciones pequeñas aplicadas:** solo este inventario con archivos/líneas verificables y su test contractual offline. **No se modificaron componentes**, estilos, runtime ni plantillas. Ninguna corrección de UI se declara hecha sin hoja, claims y tests propios.

## Matriz de verificación pendiente por etapa

La secuencia de teclado, la visibilidad del foco, el reflow y la comunicación de errores necesitan una ejecución autenticada en navegador sobre un artefacto identificado; las referencias a componentes prueban **estructura en fuente**, no conformidad. Esta matriz es una **PROPUESTA** de observación, no una lista de resultados aprobados:

| Etapa | Teclado y foco a ensayar | Mensajes de error a comprobar | Contraste y móvil | Estado |
| --- | --- | --- | --- | --- |
| Login | Tab desde «Saltar al contenido», foco de email a contraseña y botón Entrar; regreso de foco después de un error | `role="alert"` y enlaces de `aria-describedby` del fallo de sesión; lectura con tecnología asistiva | Medir ratios de texto/controles en normal, error y foco a 320 CSS px | NO ENSAYADO |
| Subir material | Recorrer selector de archivos, descartes y botón Guardar sin trampas de foco | Anuncio de rechazos de formato/tamaño, progreso y resultado por archivo; `VaultPanel.tsx:703-769` | Reflow de lista de archivos y controles con zoom 400 %; contraste de botones deshabilitados y mensajes | NO ENSAYADO |
| Programar | Tab por recurso, horario y botón; comprobar foco tras error de red; `ScheduleDraftPanel.tsx:568-594` | `role="alert"` y `role="status"` del formulario, sin suponer que lectores de pantalla los anuncian | Reflow de selects y etiquetas a 320 CSS px; contraste de error y foco | NO ENSAYADO |
| Preparar/publicar | Recorrer botones condicionales, destinos manuales y regiones desplazables; `ManualHandoffPanel.tsx:116-123` | Distinguir `prepared`, `published`, `failed` y estado incierto sin equipararlos a publicación efectiva | Reflow de tablas con scroll horizontal accesible y medición de contraste AA | UNKNOWN / NO ENSAYADO |

**Criterio de evidencia:** conservar build/SHA desplegado, viewport, dispositivo o navegador, pasos, resultado accesible, fecha y capturas anonimizadas. Un resultado positivo en fixtures o CI no reemplaza la auditoría real de la fila `DEL_DUEÑO`.

## Pruebas pendientes y responsables

- **NO ENSAYADO:** navegación real de login, subida, programación y publicación con teclado y lector de pantalla en build desplegado identificado. OWNER y QA.
- **UNKNOWN:** contraste AA, reflow móvil y mensajes en todos los estados, con evidencia del navegador, fecha, viewport, screenshot y SHA. QA.
- **NO ENSAYADO:** retorno de foco y anuncio tras errores de subida, programación, sesión y entrega. QA.
- **PROPUESTA:** abrir hojas específicas para defectos reproducidos, con rutas y aceptación, antes de modificar UI o despliegue.

## Puerta de salida y reversión

Fuente canónica: [`docs/lista-salida-produccion.md`, fila «Teclado, foco, móvil y errores en journey real»](../lista-salida-produccion.md) bajo Accesibilidad y rendimiento, enlazada a [#344](https://github.com/pl0n3r/GrindFlow/issues/344). El estado real sigue **`DEL_DUEÑO`**: requiere verificación de OWNER/QA sobre el artefacto desplegado. Este issue no altera esa fila, **no autoriza go-live** y no certifica producción. Reversión: retirar exclusivamente el documento y `tests/test_a11y_offline_doc.py` mediante PR autorizado.
