# GrindFlow · S0 Symfony aislado

**S1 fundacional (no desplegado):** entidades y migración Doctrine de usuarios, organizaciones y membresías prefijadas `gf_identity_*`, probadas en MariaDB descartable; todavía sin login/onboarding y `/admin` sigue 403. **Implementado en código (no desplegado):** primera superficie Symfony 7.4 LTS, home Twig, vista previa React/Vite compilable y health seguro. La versión se lee del `../config/version.php` del repositorio. **No se conecta a cuentas, datos ni tablas de Laravel** y `/admin` responde 403 intencionadamente hasta S1; `/preview` es una demostración pública rotulada que no simula publicación externa.

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

Plan de transición y criterios completos: [STACK-TRANSITION-SYMFONY.md](../../docs/STACK-TRANSITION-SYMFONY.md); [Issue #12](https://github.com/pl0n3r/GrindFlow/issues/12).

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
