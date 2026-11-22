# GF-MIG-003: matriz HTTP Laravel a Symfony de alcance seguro (#1052)

Este inventario registra las **43 rutas HTTP**: 42 declaradas en `routes/web.php` más `GET /up` de `bootstrap/app.php` (incluidos verbos y prefijo de organización). `routes/console.php` y otros activos Next.js, worker y Supabase conservan sus contratos propios en `docs/LEGACY-DEPENDENCY-INVENTORY.md` y `tests/test_gf350_retirement_eligibility.py`. No existe `routes/api.php` en esta revisión.

**GET y HEAD:** Laravel admite `HEAD` implícito para rutas `GET`; el inventario enumera los verbos declarados y la ruta de salud del framework. La equivalencia de `HEAD` también está pendiente de pruebas, sin declarar paridad. **Estado: NO-GO.** La existencia de un atributo Symfony y un archivo de test no acredita equivalencia funcional. Todas las filas son `UNKNOWN` y `Retirar=NO`; no se borra código, migra información real, despliega ni se altera la decisión del dueño.

## Requisitos de la prueba de paridad

La prueba `tests/test_gf_mig_003_parity.py` compara este conjunto contra las declaraciones Laravel reales y contra `bootstrap/app.php` y comprueba la presencia de métodos, paths Symfony candidatos y archivos de pruebas. **Candidato ≠ paridad.** Son obligatorias evidencias separadas en las ocho dimensiones `tenant_isolation`, `roles`, `routes`, `data`, `states`, `identifiers`, `history`, `write_ownership` antes de aceptar una equivalencia, con negativos de cross-tenant, permisos, estados, idempotencia y escrituras.

El conjunto `REVIEWED_PARITY_KEYS` está vacío intencionalmente. Para registrar `PARITY` se requieren pruebas comparativas revisadas de extremo a extremo, no editar este Markdown. El retiro se controla además por GF-MIG-004: restauración de backup observada, rollback probado, ausencia de doble escritor, observación independiente, autorización OWNER y controles de privacidad. **CI verde ≠ permiso de retiro ni validación productiva.**

## Matriz HTTP por ruta

| Ruta Laravel | Nombre Laravel | Ruta Symfony candidata | Código Symfony candidato | Prueba candidata | Dimensiones | Paridad | Retirar |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `GET /` | `home` | `GET /` | `symfony/src/Http/Controller/HomeController.php` | `symfony/tests/php/RouteImportCoverageTest.php` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `GET /privacidad-y-mis-derechos` | `privacy.rights` | `GET /privacidad-y-mis-derechos` | `symfony/src/Http/Controller/PrivacyRightsController.php` | `symfony/tests/php/DataSubjectRequestsTest.php` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `GET /health` | `health.exact` | `GET /health` | `symfony/src/Http/Controller/HealthController.php` | `symfony/tests/php/ReleaseShaHealthTest.php` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `GET /up` | `-` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `POST /internal/production-smoke/bootstrap` | `production-smoke.bootstrap` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `GET /_deployment` | `deployment.marker` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `GET /l/{token}` | `traffic.redirect` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `GET /guest-upload/{token}` | `guest.upload.show` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `POST /guest-upload` | `guest.upload.store` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `GET /login` | `login` | `GET /login` | `symfony/src/Http/Controller/LoginController.php` | `symfony/tests/php/IdentityLoginTest.php` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `POST /login` | `-` | `POST /login` | `symfony/src/Http/Controller/LoginController.php` | `symfony/tests/php/IdentityLoginTest.php` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `GET /dashboard` | `dashboard` | `GET /admin` | `symfony/src/Http/Controller/AdminController.php` | `symfony/tests/php/AdminUnauthenticatedResponseTest.php` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `GET /organizations/{organizationId}/vault` | `organizations.vault.index` | `GET /api/admin/vault` | `symfony/src/Http/Controller/VaultController.php` | `symfony/tests/php/VaultTest.php` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `POST /organizations/{organizationId}/vault` | `organizations.vault.store` | `POST /api/admin/vault` | `symfony/src/Http/Controller/VaultController.php` | `symfony/tests/php/VaultTest.php` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `GET /organizations/{organizationId}/scheduler` | `organizations.scheduler.index` | `GET /api/admin/schedules` | `symfony/src/Http/Controller/ScheduleDraftController.php` | `symfony/tests/php/ScheduleDraftTest.php` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `POST /organizations/{organizationId}/scheduler` | `organizations.scheduler.store` | `POST /api/admin/schedules` | `symfony/src/Http/Controller/ScheduleDraftController.php` | `symfony/tests/php/ScheduleDraftTest.php` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `PATCH /organizations/{organizationId}/scheduler` | `organizations.scheduler.update` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `PATCH /organizations/{organizationId}/scheduler/tracked-link` | `organizations.scheduler.tracked-link.update` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `POST /organizations/{organizationId}/scheduler/cancel` | `organizations.scheduler.cancel` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `GET /organizations/{organizationId}/distribution` | `organizations.distribution.index` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `POST /organizations/{organizationId}/distribution/destinations` | `organizations.distribution.destinations.store` | `POST /api/admin/manual-destinations` | `symfony/src/Http/Controller/ManualDestinationController.php` | `symfony/tests/php/ManualDestinationTest.php` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `PATCH /organizations/{organizationId}/distribution/destinations/{destinationId}` | `organizations.distribution.destinations.update` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `POST /organizations/{organizationId}/distribution/deliveries/{deliveryId}/retry` | `organizations.distribution.deliveries.retry` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `GET /organizations/{organizationId}/traffic` | `organizations.traffic.index` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `GET /organizations/{organizationId}/traffic/export` | `organizations.traffic.export` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `POST /organizations/{organizationId}/traffic` | `organizations.traffic.store` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `PATCH /organizations/{organizationId}/traffic/links/{linkId}` | `organizations.traffic.links.update` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `PATCH /organizations/{organizationId}/traffic/links/{linkId}/status` | `organizations.traffic.links.status` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `GET /organizations/{organizationId}/finance` | `organizations.finance.index` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `GET /organizations/{organizationId}/finance/reconciliation.csv` | `organizations.finance.reconciliation.export` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `POST /organizations/{organizationId}/finance` | `organizations.finance.store` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `POST /organizations/{organizationId}/finance/{allocationId}/reverse` | `organizations.finance.reverse` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `POST /organizations/{organizationId}/vault/direct-upload` | `organizations.vault.direct.create` | `POST /api/admin/vault/direct-upload/intent` | `symfony/src/Http/Controller/DirectUploadController.php` | `symfony/tests/php/DirectUploadHttpTest.php` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `POST /organizations/{organizationId}/vault/direct-upload/complete` | `organizations.vault.direct.complete` | `POST /api/admin/vault/direct-upload/complete` | `symfony/src/Http/Controller/DirectUploadController.php` | `symfony/tests/php/DirectUploadHttpTest.php` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `GET /organizations/{organizationId}/connections/dropbox/authorize` | `organizations.connections.dropbox.authorize` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `GET /organizations/{organizationId}/connections/google-drive/authorize` | `organizations.connections.google-drive.authorize` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `GET /connections/dropbox/callback` | `connections.dropbox.callback` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `GET /connections/google-drive/callback` | `connections.google-drive.callback` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `GET /admin/system` | `admin.system` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `POST /admin/system/migrations` | `admin.system.migrate` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `GET /admin/diagnostics` | `admin.diagnostics` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `GET /admin/diagnostics.json` | `admin.diagnostics.json` | `-` | `-` | `-` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |
| `POST /logout` | `logout` | `POST /logout` | `symfony/src/Http/Controller/LoginController.php` | `symfony/tests/php/IdentityLoginTest.php` | `PENDIENTE_8_8` | `UNKNOWN` | `NO` |

## Límites, comprobación y reversión

- Ejecutar `python3 -m unittest tests/test_gf_mig_003_parity.py -v`: prueba negativa de fila ausente, duplicada o inventada, método/path candidato falso, `PARITY` sin evidencias y cualquier `Retirar=SI` sobre `UNKNOWN`.
- Esta tabla no afirma equivalencia de las rutas con distinto path, sesión, CSRF, rol, organización, acceso a datos o ownership; los candidatos son hipótesis no acreditadas.
- El inventario histórico de 26 dependencias y los gates de `tests/test_migration_parity_exit_gate.py`, `tests/test_legacy_retirement_guard.py` y `tests/test_gf350_retirement_eligibility.py` siguen siendo exigibles.
- Reversión: revertir el squash documental de #1052. No se toca producción ni se realiza GF-MIG-004.
