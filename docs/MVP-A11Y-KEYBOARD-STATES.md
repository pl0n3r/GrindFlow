# MVP · Accesibilidad de foco, teclado y estados (hoja #532)

Esta hoja crea un **contrato de navegador real (Chromium headless)** sobre un DOM efímero y sintético. No son mocks de Chrome ni assertions sobre texto CSS; cada ejecución arranca el navegador y obtiene el estado del DOM después de ejecutar JavaScript. No se accede a red, sesiones de clientes, producción, pagos ni credenciales.

## Cobertura ejecutable

- `tests/test_mvp_a11y_keyboard_states.py::MvpA11yKeyboardStatesTests::test_forms_filters_and_pagination_keyboard_focus`: foco real de Chromium sobre filtro y botón, label asociada al input, pseudoclase CSS `:focus` activa, tecla Enter entregada al handler de paginación y estado de página anunciado con `aria-live`. Las variantes negativas desactivan foco, label y handler por separado; todas deben fallar.
- `tests/test_mvp_a11y_keyboard_states.py::MvpA11yKeyboardStatesTests::test_loading_empty_and_error_states_accessible`: DOM real en los estados `loading`, `empty` y `error`, roles `status`/`alert` y `aria-live`. Una variante sin canal de anuncio falla.
- La ejecución está conectada a `scripts/browser-smoke.sh`, que ya se ejecuta desde el job `browser` de CI. Se pasa el binario descubierto con `GRINDFLOW_BROWSER_CHROME`, sin tocar el workflow reservado por #504.

## Ejecución y criterios de corte

```bash
GRINDFLOW_BROWSER_CHROME="$(command -v google-chrome || command -v chromium)" \
  python3 -m unittest tests/test_mvp_a11y_keyboard_states.py -v
```

Si Chromium no está disponible, el test **falla**: nunca simula resultado verde ni lo omite. Se comprueba el contenido del nodo `#result` del DOM renderizado; no se buscan cadenas que también aparezcan dentro del JavaScript fuente. Los perfiles/navegadores se ejecutan en carpeta temporal; `--disable-background-networking` reduce red incidental.

**Límite explícito:** este test certifica el contrato de interacción/semántica de la fixture local. No demuestra por sí solo que cada pantalla autenticada del producto implemente esos atributos: el flujo autenticado Scheduler → Traffic → Finance permanece cubierto por `scripts/browser-workflow.sh` y requiere su propia evidencia terminal. No se declara aceptación productiva, E2E integral ni fase live a partir de estas fixtures.

**Reversión:** revertir exclusivamente los archivos de #532. El siguiente leaf #533 añade otra batería de Chromium sobre viewports cuando esta hoja esté fusionada y su reserva V3 liberada.
