# Reglas de Smoke y E2E

Este documento es la fuente canónica para las reglas operativas de Smoke de producción y E2E autenticado de GrindFlow. Las reglas conservan la semántica normativa previamente ubicada en `AGENTS.md`.

### Credenciales de Smoke: no aceptar verde sin autenticacion

- Si falta `PRODUCTION_E2E_PASSWORD` en el repositorio activo, registrar
  el incidente de configuración y terminar el workflow con error.
  No presentar un job omitido como validación de producción satisfactoria.
- Comprobar este comportamiento en el contrato de sintaxis del workflow;
  nunca registrar el valor de la credencial ni probar login con secretos reales
  en el CI de pull requests.

### Smoke de produccion vertical / version observada

- Leer version humana numerica desde el marcador exclusivo Admin System
  `data-grindflow-release`, con fallback de texto seguro para releases antiguos.
  Distinguir `RELEASE_UI_OBSERVED` de `RELEASE_UI_EXPECTED`; nunca imprimir
  el HTML completo de Admin System ni secretos. Fallo de version desconocida o
  distinta es determinista, sin 15 reintentos/login repetidos y sin declarar
  Hostinger SHA por inferencia.

- Reutilizar UNA sesion E2E de produccion para GET seguros de Vault,
  Scheduler, Distribution, Traffic, Finance y CSV diario; nunca ejecutar
  links publicos /l/* (generan clicks) ni POST/PATCH durante el smoke.
- Confirmar marcador de readiness de cada modulo y cabeceras/encabezado del
  CSV sin publicar cuerpo de reportes, cookies ni datos de tenants en issues.
- Comparar vX.Y.Z del Admin System observado contra el version.php del
  checkout de CI, sin confundir version humana con SHA de deploy.
- Cuando hay migraciones pendientes, preservar el inventario y check de Vault
  read-only; no ejecutar checks nuevos que dependan del schema ni reiterar login.
- Un modulo autentico averiado falla cerrado sin reintentos/login reiterados;
  el diagnostico queda en artifact de corta retencion. Anotar en run/issue que
  GITHUB_SHA es fuente del workflow, NO checkout confirmado en Hostinger.

### E2E en navegador autenticado (CI aislado)

- `scripts/browser-smoke.sh` termina ejecutando
  `scripts/browser-workflow.sh` en SQLite descartable del
  browser job y MariaDB descartable del real-stack job (`APP_ENV=testing`), usando `E2eSeeder` con 106 assets y
  links sinteticos, 27 schedules, 7 clicks diarios y 2500 COP de Finance.
  Seeder prohibe entornos no local/testing y debe ser idempotente.
- El recorrido browser descubrio 404 falso en reverse Finance: la ruta padre
  tambien posee {organizationId}, y un argumento scalar $allocationId
  en Controller puede recibir ese ID por posicion. En acciones bajo
  /organizations/{organizationId} usar $request->route('allocationId')
  para identificar la fila financiera, nunca confiar en el primer scalar.
  Probar POST HTTP POSITIVO de reversa, no solo 404 cross-tenant/manager.
- Chrome ejecuta JavaScript real y envia formularios DOM con CSRF/session
  para Scheduler create, paginar, asociar/desvincular link, Traffic
  editar/disable/enable y Finance alloc/reversal/CSV.
  No sustituye clicks fisicos ni prueba producción, pero verifica
  flujo HTTP real en sesion Chromium y respuesta HTML.
- Nunca ejecutar este workflow contra Hostinger: crea filas del tenant
  descartable. Production Smoke conserva GETs solo lectura y no visita
  /l/*, que genera clicks.
- La plantilla publica temporal contiene password del usuario E2E solo
  durante el comando; borrar en trap y remover script del DOM ANTES de
  capturar artifact. En workflow Github Actions, ejecutar ::add-mask::
  ANTES de exportar E2E_USER_PASSWORD a GITHUB_ENV; si no, el runner
  puede mostrar la clave temporal en el entorno de los logs del step.
  Rechazar/descartar DOM si aparece password, nunca
  imprimir HTML ni payloads de respuestas en logs ante fallo. No tomar
  captura adicional que reejecute workflow y duplique writes.
- Los fixtures de media son METADATA sintética procesada, no prueba
  de subir bytes ni de proveedor/FFmpeg remoto. Browser job necesita
  layout, auth y rutas reales, pero no cuentas ni tokens externos.
- Cada nuevo modulo UI deberia ampliar recorrido positivo/negativo en
  navegador cuando aporte mas que otro assert HTML PHPUnit.

### Regla de pruebas E2E eficientes

- Usar el usuario sintetico E2E existente para validar **todo lo razonable** en
  produccion cuando la prueba sea de solo lectura o tenga un efecto controlado y
  reversible ya aprobado.
- Agrupar validaciones E2E en la misma sesion autenticada siempre que sea posible
  para evitar logins repetidos, recargas, esperas y nuevos runs innecesarios.
- Antes de pedir una prueba manual al operador, intentar primero cubrirla con el
  usuario E2E, Production Smoke o el bridge diagnostico existente.
- No agregar una recarga, retry, browser pass o espera adicional si la misma
  evidencia puede obtenerse dentro de un flujo E2E que ya esta activo.
- Las pruebas E2E no deben mutar datos de produccion salvo que esa mutacion sea
  la accion explicitamente aprobada que se esta validando. Migraciones,
  eliminaciones, publicaciones y operaciones destructivas siguen requiriendo
  aprobacion separada.
- Una prueba E2E eficiente puede comprobar en una sola pasada salud, login,
  Dashboard, System, pending migrations, Diagnostics y Vault cuando esas rutas
  ya forman parte del alcance del cambio.
- Optimizar E2E significa maximizar evidencia por sesion, **no** reducir cobertura
  ni esconder fallos mediante retries excesivos.
