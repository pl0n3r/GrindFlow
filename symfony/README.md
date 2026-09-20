# GrindFlow · S0 Symfony aislado

**Implementado en código (no desplegado):** primera superficie Symfony 7.4 LTS, home Twig, vista previa React/Vite compilable y health seguro. La versión se lee del `../config/version.php` del repositorio. **No se conecta a cuentas, datos ni tablas de Laravel** y `/admin` responde 403 intencionadamente hasta S1; `/preview` es una demostración pública rotulada que no simula publicación externa.

## Ejecutar en entorno descartable

Requisitos: PHP 8.3+ compatible con Symfony 7.4 (objetivo productivo PHP 8.5, verificar Hostinger), Composer, Node 22, extensiones PHP necesarias. Para S0 no se requiere migrar una base, pero Doctrine está configurado para una MariaDB **aislada** mediante `DATABASE_URL`.

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

## S1 · Primera pieza de identidad (v0.1.31)

La migración versionada `migrations/Version20260920095000.php` prepara **gf_users**, **gf_organizations** y **gf_memberships** en una base Symfony **independiente**. Incluye claves UUID compatibles por formato con el modelo Laravel, membresía única por pareja organización/usuario, FK, rol, cuenta activa y trigger que impide cambiar la identidad de una membresía. No importa personas ni contraseñas existentes y no se ejecuta en la MariaDB de Hostinger.

El gate `symfony-preview` crea el esquema en MariaDB descartable, lo revierte y lo vuelve a crear antes de las pruebas de aislamiento. `/admin` sigue 403 hasta implementar Symfony Security, login/logout, selección y permisos en un próximo slice; esta entrega **no presenta una pantalla de login falsa**. El marcador S0/preview conserva su significado.
