# CI de GrindFlow · optimización continua

Estado objetivo de este ciclo: **v0.1.36 (código/CI, no despliegue Symfony)**.

## Ajuste automático y límites

`scripts/ci-scope.sh` conserva la matriz de selección por rutas y la unión
segura de rutas mixtas. Solo documentación (incluida Symfony) exige contratos
rápidos y validación del README. El código Symfony conserva MariaDB descartable,
pruebas PHP, compilación React y navegador. Alterar scripts nucleares del CI
obliga a probar todos los gates; `workflow_dispatch` también lo hace.

`scripts/database-test-runner.sh` inspecciona grupos de PHPUnit/Pest. Si existe
`database`, ejecuta ese grupo; si no, ejecuta toda la suite **una sola vez**.
Si falla una prueba agrupada, conserva su error original sin reintentar la suite
completa y gastar tiempo en un segundo resultado engañoso.

Chromium usa cache por sistema operativo y lockfile Symfony. La caché es
**solo binaria**, nunca datos de tests, sesiones, credenciales o base de datos.
Un lockfile nuevo o cache miss fuerza instalación normal. Las dependencias del
sistema se verifican en todos los runs, incluso con binario recuperado.

## Telemetría diaria

`.github/workflows/ci-health.yml` ejecuta diariamente y bajo demanda un
resumen legible en GitHub Actions con muestras por evento (PR y push).
`scripts/ci-performance-report.py` usa la API de Actions en modo lectura:

- hasta 20 runs completos por evento tomados de los 50 más recientes;
- descarta cancelados y ejecución manual, que tienen coste distinto;
- mediana y p90 de duración aproximada;
- alerta diagnóstica cuando últimos cinco superan los cinco anteriores
  en mediana por **más de 35 % Y más de 30 segundos**, o cuando al
  menos 3 de 5 fallan y empeoran respecto a la ventana anterior.

Cuando no hay muestra suficiente, **no** se estima tendencia. El reporte no
modifica el CI, no abre PRs/Issues automáticamente, no reintenta pruebas,
no reduce cobertura y no usa secretos externos; emplea el token de Actions
solo para lectura. Toda optimización posterior exige revisión de causa raíz,
pruebas de contrato y CI verde en PR y SHA exact-main.

## Señales de éxito y límites

Comparar duración por tipo de evento, fallos determinísticos frente a fallos
de descarga/red, número de reconstrucciones canceladas y calidad del diagnóstico.
Nunca calificar como «flaky» un fallo sin evidencia reproducible. Una caché
caliente acelera instalación, pero no garantiza menor tiempo si el runner
de GitHub está congestionado. CI verde no acredita checkout Hostinger.
