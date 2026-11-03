# GrindFlow #801 · Symfony cutover — procedimiento candidato, NO desplegado

Este documento **no** cambia el front-controller ni activa un despliegue. El runtime observado en el repositorio sigue siendo **Laravel en `public/index.php`**, con `.htaccess` raíz hacia `public/`; `public/.htaccess` enruta únicamente `/s4/*` a `public/s4.php` (puente Symfony). Symfony sirve su propio `symfony/public/index.php` y `/health` con `release_sha`. Un cambio de código en este PR no demuestra que Hostinger haya cambiado su document root.

**Autoridad:** solo el propietario activa el cutover mediante el mecanismo de despliegue aprobado. No usar secretos, datos reales, escrituras dobles, `schema:update --force`, borrados de Laravel, ni publicar una versión sin SHA verificable. Si una señal es `UNKNOWN`, el resultado es **NO-GO**. El retiro de fuentes Laravel será un PR posterior, nunca una consecuencia automática de este runbook.

## Tabla de equivalencia y redirecciones (evidencia de código, NO paridad certificada)

| URL pública anterior | Controlador de origen | Destino Symfony candidato | Condición antes de activar |
| --- | --- | --- | --- |
| `/` | Laravel `welcome` | Symfony `HomeController` `/` | equivalencia de contenido y caché verificada |
| `/login` | Laravel sesión | Symfony `LoginController` `/login` | CSRF, sesión y cookies de seguridad comprobados |
| `/dashboard` | Laravel `DashboardController` | Symfony `/admin` | 302 solo tras equivalencia de roles/tenant; no redirigir a ciegas |
| `/health` | Laravel JSON `commit` | Symfony `HealthController` JSON `release_sha` | SHA exacto y 200 con `status=ok`; nombres de campos diferentes |
| `/l/{token}` | Laravel tracked-link | **SIN EQUIVALENCIA PROBADA** | NO-GO; preservar redirección, atribución y token |
| `/guest-upload/{token}` | Laravel guest upload | **SIN EQUIVALENCIA PROBADA** | NO-GO; proteger carga y sesión sin fuga de datos |
| `/organizations/{id}/vault` | Laravel Vault | **SIN EQUIVALENCIA PROBADA** | NO-GO; comparar IDs, permisos y tenant |
| `/s4/*` | puente actual Symfony | Symfony sin prefijo tras cambio | comprobar rutas, URLs generadas y redirecciones |

No publicar 301 permanentes antes de verificar paridad de las URLs, los métodos HTTP, la propiedad del dato, cabeceras y los destinos. Las APIs/webhooks no inventariadas también son **NO-GO**. No desplazar a Symfony enlaces que todavía solo entiende Laravel.

## Secuencia estricta: operador, con evidencias fechadas

### 01 — Guardas y SHA anterior
Registrar el SHA de **main**, SHA **real** que sirve Hostinger, versión visible y responsable; comprobar kill switch Factory #767 `RUNNING`, freeze #921, protección y autorización de despliegue. Guardar `PREVIOUS_SHA` de 40 hexadecimales, artefacto Laravel íntegro y ruta de restauración. Si el SHA vivo no se puede atribuir inequívocamente, **NO-GO**.

### 02 — Backup y prueba de restauración (#780)
Antes de cambiar tráfico o escritores: obtener backup cifrado verificable de **MariaDB y blobs**, checksums, retención y evidencia de **restauración aislada** del mismo snapshot. Registrar el responsable y la ubicación privada **fuera del repositorio**. Si #780 no acredita la restauración y recuperabilidad, **NO-GO**. Nunca imprimir secretos ni URLs firmadas en el log.

### 03 — Hostinger de solo lectura
El propietario ejecuta desde el checkout destinado a despliegue `bash scripts/hostinger-readonly-check.sh --read-only` y conserva la salida **sin secretos**. Comprueba PHP CLI >=8.3 (objetivo 8.5), extensiones, Composer, `symfony/vendor/autoload.php`, front-controller y permisos CLI. La versión mínima declarada actualmente es `symfony/composer.json: php ^8.3`. Separadamente contrastar PHP-FPM/web, vhost/document root real, rewrite Apache, permisos con la identidad de servicio, OPcache y backups. `CANDIDATE_ONLY` **no** significa GO; cualquier `NO_GO` o `UNVERIFIED` pendiente bloquea la ejecución.

### 04 — Paridad, datos y un solo escritor
Validar la tabla de rutas completa, 302/404, sesiones, roles, IDs, tenant, UTC, claves, blob storage, cron/colas, sesiones y endpoints públicos. Preparar el artefacto Symfony preconstruido, migraciones reversibles y una ventana de mantenimiento; **no** ejecutar `doctrine:schema:update --force`. Congelar escrituras del runtime anterior antes de habilitar las nuevas. No permitir **dos escritores simultáneos** a las mismas tablas, colas o eventos. Un enlace crítico sin equivalencia demostrada es **NO-GO**.

### 05 — Conmutación por el propietario
Con autorización humana y criterios anteriores en verde, el propietario ejecuta el flujo de despliegue existente que selecciona **un único** front-controller Symfony, elimina la vía de escritura Laravel y redirige solo las rutas con equivalencia verificada. No ejecutar este paso desde CI ni desde este PR. Registrar `TARGET_SHA`, la configuración anterior y el instante del cambio para reversión inmediata.

### 06 — `/health` exact-SHA
Observar por HTTP el `/health` público servido por Symfony después del cambio. Exigir `status=ok`, código 200, `release_sha` de 40 hexadecimales **igual a `TARGET_SHA`**, versión esperada y cabeceras sin caché; no aceptar el `commit` Laravel ni un tag como sustituto del SHA. Ausencia de evidencia, 503, timeout o SHA diferente = **NO-GO / rollback**.

### 07 — Smoke autenticado y tenant (#794)
Con credenciales sintéticas/privadas solo del entorno de validación, comprobar login/CSRF, organización y acceso negativo cross-tenant mediante el smoke oficial de #794. Exigir resultado terminal, logs saneados, URLs críticas y ninguna escritura doble. Cualquier error, dependencia #794 sin validar o `UNKNOWN` = **NO-GO / rollback**. La salud básica no sustituye un smoke real.

### 08 — Vuelta atrás inmediata y cierre
Si 05–07 fallan, congelar nuevas escrituras Symfony, restaurar de forma controlada el **artefacto y configuración del `PREVIOUS_SHA`**, reabrir **un único** escritor Laravel solo cuando sea seguro y verificar `/health` y smoke de ese SHA. Si hubo datos nuevos, decidir reconciliación/restore con el propietario antes de volver a escribir; nunca restaurar DB encima de datos nuevos sin decisión explícita. Conservar evidencias y abrir incidente. No borrar Laravel hasta un PR **posterior** tras paridad productiva demostrada; sin ese hito, mantenerlo intacto.

## Estado contractual de este PR

**PREPARACIÓN OFFLINE SOLAMENTE.** No se ha ejecutado Hostinger, backup, cutover, migración, smoke productivo, rollback, despliegue ni eliminación Laravel. Los resultados de PHPUnit y CI solo demuestran la consistencia del runbook y del checker; no demuestran preparación ni validación en producción.
