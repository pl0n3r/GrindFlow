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
