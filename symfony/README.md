# GrindFlow · Runtime Symfony aislado

> **Estado del slice v0.1.49, rama del PR #42 (aún sin merge):** Symfony 7.4 + MariaDB descartable, login/logout, organizaciones y permisos reales, y Vault privado móvil de imágenes con búsqueda, filtros y carga múltiple recuperable. Laravel sigue atendiendo el sitio productivo. El panel Symfony NO está desplegado en Hostinger y no se han migrado usuarios, imágenes ni tablas productivas.

| Etapa | Evidencia separada |
| --- | --- |
| IMPLEMENTADO | v0.1.49 preparada en rama; filtros MIME/orden de main v0.1.48 se conservan junto con la recuperación de cargas. |
| VALIDADO EN CÓDIGO | CI del PR #42 y Sonar deben pasar en el head final; CI exact-main de v0.1.48 success. |
| DESPLEGADO | No: Symfony no se ha instalado ni activado en Hostinger. El Observer de Laravel v0.1.48 no certifica Symfony ni SHA remoto. |
| VALIDADO EN PRODUCCIÓN | No: Smoke autenticado sigue sin credencial E2E; la MariaDB de Symfony es solo descartable. |

Fuente del snapshot de release: [README principal](../README.md). Historial del producto y prioridades: [roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2). Los apartados con versiones antiguas más abajo describen el alcance **en aquella entrega**, no el estado vigente.

## Ejecutar en entorno descartable

Requisitos: PHP 8.3+ compatible con Symfony 7.4 (objetivo productivo PHP 8.5, verificar Hostinger), Composer, Node 22, extensiones PHP necesarias. S1 requiere una MariaDB **aislada**, expresamente NO la de Laravel ni Hostinger productivo, mediante `DATABASE_URL`. Migrar solo en un entorno descartable con `php bin/console doctrine:migrations:migrate --no-interaction`; probar reversión únicamente allí. No usar `doctrine:schema:update --force`.

```bash
cd symfony
composer install --no-interaction --prefer-dist
npm ci --no-audit --no-fund
npm run typecheck
npm run build
APP_ENV=test APP_DEBUG=0 APP_SECRET="$(php -r 'echo bin2hex(random_bytes(32));')" php -S 127.0.0.1:8765 -t public public/router.php
```

Visitar `http://127.0.0.1:8765/` (Twig), `/preview` (React) y `/health` (estado S0, solo versión humana). No publicar este directorio en `public_html` mientras Laravel continúe en producción. Desde otra terminal, con `APP_SECRET` de pruebas y servidor local, ejecutar `bash tests/contract/smoke.sh`, `php vendor/bin/simple-phpunit -c phpunit.xml.dist` y `npm run test:e2e` si Chromium está instalado.

## Fronteras de seguridad

- Preview **no pide login, no expone contenido ni hace escrituras**; no confundir con el verdadero dashboard administrativo.
- S1 establecerá Symfony Security, sesiones/CSRF y modelo de tenant antes de habilitar rutas privadas.
- Contraseñas, tokens de proveedores, blobs y migraciones de producción nunca entran en esta demo.
- Twig público y React admin no duplican dominio; Node solamente compila archivos, **no** es runtime productivo.
- Los assets se sirven desde `public/build` con manifiesto Vite validado. Si falta, `/preview` devuelve 503 explícito.
- `php -S` y `APP_SECRET` del ejemplo son solo para desarrollo; no usar en Hostinger productivo.

Plan de transición y criterios completos: [STACK-TRANSITION-SYMFONY.md](../docs/STACK-TRANSITION-SYMFONY.md); [Issue #12](https://github.com/pl0n3r/GrindFlow/issues/12).

## S1 · Integridad reversible de membresías (v0.1.32)

La migración adicional `Version20260920095500` instala en MariaDB **aislada** una garantía DB que impide reasignar el usuario o la organización de una membresía existente. Cambiar el rol sigue permitido; cambiar de usuario u organización requiere reemplazar la membresía. PHPUnit verifica ambas prohibiciones con dos usuarios y dos organizaciones sintéticos; CI revierte primero el trigger y después las tablas, luego reaplica ambas migraciones. `/admin` Symfony continúa 403, todavía no hay inicio de sesión. No aplicar estas migraciones a la MariaDB de Laravel/Hostinger.

## S1 acceso y selección de organizaciones, v0.1.33

El entorno Symfony aislado incorpora login/logout mediante Symfony Security, protección CSRF, limitación de intentos y comprobación de cuenta activa. Solo se listan organizaciones con membresía del usuario; la elección exige CSRF y revalidación servidor, y cada GET al admin vuelve a comprobarla. El admin anuncia expresamente que Vault/automatización todavía no están conectados. Ninguna cuenta ni tabla productiva Laravel se modifica o migra; la protección de membresías inmutables de v0.1.32 permanece.


## S1 panel React protegido, v0.1.34

El admin privado monta React desde el manifiesto Vite existente y obtiene su contexto de `GET /api/admin/context`. La API responde JSON explícito para sesión ausente, organización no seleccionada o membresía revocada; vuelve a consultar MariaDB por usuario y organización en cada petición y calcula permisos conservadores por rol. La interfaz muestra la organización y las capacidades reales de la membresía, mantiene S2+ deshabilitado y no inventa datos operativos. PHPUnit cubre aislamiento, revocación y permisos; Playwright cubre el bundle responsive y el contrato de error sin sesión. No agrega migraciones ni cambia producción.

## S1 · Ajustes de organización (v0.1.35)

La administración React incorpora un formulario real para renombrar la organización activa. El permiso `organization_manage` emitido por el servidor autoriza solo las membresías `admin` y `studio`. La API `POST /api/admin/organization/name` exige sesión, selección de tenant, CSRF, nombre Unicode visible de 2 a 120 caracteres y revalida usuario activo, membresía y rol en la sentencia SQL. Se rechaza todo identificador de organización suministrado por el cliente. La API de contexto entrega el token únicamente a gestores. Pruebas PHP sobre MariaDB descartable y Playwright verifican permisos, IDOR, revocación, respuesta móvil y caché privada. Sin cambio de tablas ni datos de Laravel; Symfony no está desplegado en Hostinger.

## S1 · Perfil personal (v0.1.37)

El usuario autenticado con membresía propia puede editar su **nombre de perfil** en el panel React sin permiso administrativo sobre la organización. El POST `/api/admin/profile/name` deriva siempre el actor de la sesión, exige CSRF independiente y admite únicamente el campo `name`; IDs de otra cuenta o tenant se rechazan. La escritura SQL exige `is_active = 1`, y el GET del contexto lee el nombre vigente de MariaDB para que no reaparezca el valor anterior si la entidad de sesión está desactualizada. No se cambia contraseña, correo, organización, roles, esquema ni datos Laravel. PHPUnit prueba sesión/CSRF/IDOR/entrada inválida/revocación; Chromium verifica formulario responsive y actualización visible.

## S2 · Primera biblioteca privada móvil (v0.1.38)

El panel Symfony aislado admite ahora imágenes **JPEG, PNG y WebP** de hasta **8 MiB por archivo**, elegibles en una sola selección desde el móvil y enviadas individualmente para mostrar éxitos parciales. La API `GET /api/admin/vault` devuelve hasta 30 activos recientes únicamente de la organización elegida; `POST /api/admin/vault` exige membresía con permiso `content_prepare`, CSRF propio, verifica bytes/MIME e imagen real y revalida autorización en la escritura SQL. `GET /api/admin/vault/{id}/download` vuelve a autorizar actor y tenant, y entrega como archivo adjunto, sin vista pública, `nosniff` y `no-store`. El almacenamiento de nombres opacos está en `symfony/var/vault/` fuera de `public/`; las respuestas no publican rutas físicas ni hashes de contenido. Doctrine usa `gf_vault_assets` en MariaDB Symfony **descartable**. La reversión de migración S2 se verifica antes de revertir las tablas S1.

Este primer slice **no** incluye videos, miniaturas, procesamiento, URLs públicas, eliminación, cuotas acumuladas, deduplicación, paginación avanzada ni conexiones externas. No es paridad completa del Vault Laravel, no se importa ningún archivo o cuenta existente y **no se debe conmutar Hostinger a Symfony**. Si un almacenamiento persistente real se habilita, verificar volúmenes, backups de blobs y BD, antivirus/escaneo y límite PHP/http antes de operar con datos no descartables.

## S2 · Biblioteca paginada (v0.1.39)

El listado privado acepta `GET /api/admin/vault?page=1..1000` y devuelve `assets`, `page`, `limit=30`, `total` y `pages`. El total y cada página usan la misma membresía tenant-safe revalidada, con orden `created_at DESC, id DESC`. El panel móvil ofrece anterior/siguiente y vuelve a consultar el servidor tras subir archivos para no inventar totales. Las páginas inválidas responden JSON 422; fuera del total responden lista vacía sin acceder a otra organización. La cuota acumulada, la eliminación y los videos siguen pendientes. No se migró ni desplegó Symfony en Hostinger.

## S2 · Detalle privado de imagen (v0.1.40)

La biblioteca móvil permite abrir los metadatos de cada imagen con GET /api/admin/vault/{id}. La API revalida sesión, organización y membresía activa y responde 404 para un archivo de otra organización, sin revelar hashes ni rutas físicas. La vista muestra nombre, MIME, bytes y fecha con controles accesibles. Pruebas PHP y Chromium verifican consulta, aislamiento, revocación y vista móvil. Sin migraciones, eliminación ni despliegue Symfony productivo.

## S2 · Cuotas acumuladas por organización (v0.1.41)

En Symfony aislado cada organización tiene límites provisionales de **100 imágenes y 128 MiB acumulados**, además del límite de 8 MiB por imagen. `GET /api/admin/vault` devuelve uso real `quota.used_assets/used_bytes/max_assets/max_bytes` con el mismo filtro tenant-safe que el listado y el panel React lo presenta de forma accesible en 360 px. Al guardar, el servidor bloquea la fila de la organización mediante `SELECT ... FOR UPDATE`, calcula COUNT/SUM dentro de la transacción y revalida membresía/actor en el INSERT para serializar subidas concurrentes del mismo tenant; al alcanzar cualquier límite responde JSON 409 `vault_quota_exceeded` y borra el blob del intento rechazado. Otras organizaciones no consumen la cuota. El selector múltiple conserva éxitos parciales y muestra cada rechazo. PHPUnit comprueba ambos topes, ausencia de blob huérfano y aislamiento; Chromium muestra la cuota y los resultados parciales. **Estos topes iniciales de prueba no son precios ni planes comerciales aprobados**, y siguen pendientes la configuración de cuotas por plan, videos, eliminación segura, deduplicación, backups de almacenamiento y despliegue Symfony real. Sin migraciones nuevas.

## S2 · Papelera reversible con restauración (v0.1.42)

La biblioteca privada introduce `GET /api/admin/vault?view=active|trash&page=N` y movimientos `POST /api/admin/vault/{id}/trash`, `POST /api/admin/vault/{id}/restore`. La acción exige sesión, organización seleccionada, rol con `content_prepare` (admin/studio/editor) y CSRF **independiente** de upload. IDs de otros tenants dan 404 y no se aceptan identificadores de organización ni campos de storage desde el navegador. Cada cambio toma el mismo bloqueo SQL por organización que la ingesta para evitar estados incompatibles; escritura vuelve a comprobar actor activo/membresía vigente. El panel React móvil requiere confirmación visible antes de mover, muestra papelera y permite restaurar; model consulta ambas vistas, pero no modifica.

Las imágenes en papelera dejan de aparecer en la biblioteca activa y sus endpoints de detalle/descarga devuelven 404. **Los bytes nunca se borran físicamente aquí**; `deleted_at/deleted_by` son reversibles en MariaDB aislada. La restauración exige original privado presente, no enlace simbólico y tamaño consistente, luego vuelve a permitir lectura. **La cuota de 100 imágenes y 128 MiB incluye originales retenidos en papelera:** mover no libera espacio físico ni permite saltar límites mediante subir/mover repetidamente; restaurar no añade una segunda copia ni un segundo registro. No existe purga automática, retención con fecha límite ni eliminación permanente en esta fase; definir política/backup/auditoría antes de habilitar un runtime no descartable. Migration `Version20260920194000` es reversible y se prueba en CI antes de revertir el esquema base. No migrar a la BD de Laravel ni activar Symfony en Hostinger.

## S2 · Detección de imagen duplicada por organización (v0.1.44)

Subir a `POST /api/admin/vault` sigue validando CSRF, MIME/imagen real, bytes, rol y organización verificada. **Después del bloqueo SQL `FOR UPDATE` por organización y la comprobación de cuota**, el servidor busca un SHA-256 idéntico únicamente dentro de ese tenant, incluida la papelera. Si el original ya figura activo, responde 409 `vault_duplicate_active` y no crea una segunda imagen. Si solo aparece en papelera, responde 409 `vault_duplicate_trash` y ofrece restaurar la original. Ante ambos rechazos, el blob temporal del intento se elimina mediante `finally`; no se comparten blobs, hashes, rutas ni estado con otros tenants. Si hay registros históricos duplicados en estados distintos, se informa primero del activo. La revalidación de rol dentro de la misma transacción precede a la búsqueda de hashes para no revelar existencia a un usuario revocado.

**No se añade una restricción UNIQUE ni se reescriben datos históricos:** las subidas que cooperan con el bloqueo de organización quedan serializadas; escrituras directas fuera del controlador y datos antiguos no se consideran automáticamente saneados. No se hace borrado físico de originales ni se liberan cuotas al mover a papelera. La UI móvil preserva el mensaje de error por imagen y muestra `Ver papelera para restaurar` cuando corresponde. Rechazos por cuota mantienen su error existente; estos límites son provisionales y no equivalen a una tarifa aprobada. PHPUnit y Chromium cubren coincidencia de bytes con distinto nombre, separación de tenants, original en papelera y ausencia de blob huérfano. Sin migraciones adicionales ni despliegue Symfony productivo.

## S2 · Renombrado privado de imágenes (v0.1.46)

`POST /api/admin/vault/{id}/name` modifica solamente `original_name` en una imagen activa del tenant elegido; conserva UUID, `storage_key`, SHA-256 y bytes. Requiere CSRF de gestión, rol `content_prepare`, JSON con únicamente `name` Unicode visible de 2 a 180 caracteres y revalidación transaccional de cuenta/membresía; retorna 404 para otro tenant o imagen en papelera. El nombre actualizado se aplica también al adjunto privado al descargar. React ofrece renombrar en la biblioteca móvil, conserva el formulario ante CSRF inválido y no habilita edición en papelera ni al rol de solo lectura. PHPUnit/MariaDB y Chromium sintéticos comprueban aislamiento, denegación, original intacto y UI de 360 px. Sin migración, purga física ni despliegue Symfony en Hostinger.

## S2 · Búsqueda privada por nombre (v0.1.47)

La biblioteca Symfony incorpora `GET /api/admin/vault?view=active|trash&page=N&q=texto` y un buscador móvil de nombre de archivo en ambas vistas. El backend valida hasta 80 caracteres visibles, parametriza `LIKE` y escapa literalmente `%`, `_` y `!`; todos los recuentos, páginas y nombres siguen limitados al tenant de la sesión y al estado elegido. La cuota de bytes e imágenes permanece **global para el tenant**, también cuando la búsqueda devuelve cero resultados. La UI mantiene el filtro al alternar biblioteca/papelera y lo limpia explícitamente. Pruebas MariaDB y Chromium cubren términos que coinciden, que no coinciden, comodines como texto literal, actor ajeno y formato inválido. Sin migración, indexación pública ni despliegue Symfony a Hostinger.

## S2 · carga múltiple con recuperación parcial (v0.1.49)

El selector móvil procesa imágenes secuencialmente mediante el contrato actual de un archivo por petición. Informa progreso y estado por archivo, conserva solamente los `File` fallidos para reintento explícito y no vuelve a enviar éxitos. El backend valida cuota, tipo, CSRF y organización en cada intento. Cambiar la selección reemplaza los pendientes; el contenido no se publica externamente. La prueba Chromium cubre fallo transitorio, recuperación y ausencia de duplicados al reintentar.

Los errores de validación local, cuota, duplicados y rechazo de autorización no se dejan en la cola de reintento. Solo se conserva la selección para fallos temporales de red, HTTP 408/429/5xx. La interfaz permite descartar los pendientes sin afectar originales almacenados; no se reenvían resultados HTTP exitosos sin metadatos porque pueden haberse guardado realmente.

La clasificación de reintento ocurre **antes** de decodificar el JSON: HTTP 2xx o 4xx definitivo con cuerpo malformado no se reenvía, porque un 2xx puede haber persistido ya el archivo. Tests Chromium comprueban este contrato y usan selectores exactos para distinguir resultados de carga de nombres en la biblioteca.

## S2 · Filtrar por formato y ordenar en servidor (v0.1.48)

La biblioteca incorpora en `GET /api/admin/vault` los parámetros opcionales `format=all|jpeg|png|webp` y `sort=recent|oldest|name_asc|name_desc|size_asc|size_desc`. Los filtros de MIME y nombre se aplican **antes** del conteo/paginación y siempre dentro del tenant/vista autorizados. El orden de fechas se interpreta contra `created_at` para biblioteca y `deleted_at` para papelera; `id DESC` deshace empates sin perder ni repetir archivos entre páginas. SQL de `ORDER BY` proviene exclusivamente de una lista fija, y MIME se parametriza. La cuota de 100 imágenes/128 MiB sigue calculándose sobre todos los originales retenidos de la organización sin importar los filtros. La interfaz React permite combinar formato, nombre, vista y orden desde móvil con actualización del backend y restablecimiento de página; PHPUnit/MariaDB y Chromium 360 px verifican orden, combinaciones, cuota e entradas inválidas. No añade esquema, migración, acceso público, borrado definitivo ni deploy Symfony en Hostinger.
