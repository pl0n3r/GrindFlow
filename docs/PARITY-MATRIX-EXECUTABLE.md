# GF-MIG-003: matriz de paridad funcional ejecutable

GrindFlow #681, dependiente del roadmap #350/#2. Fuente canónica: `docs/STACK-TRANSITION-SYMFONY.md`, apartado 2. El inventario contiene sus diez dominios completos; las rutas y evidencias apuntan solo a archivos del repositorio.

**Límite de la evidencia:** `RUTA_Y_PRUEBA_EN_CODIGO` comprueba rutas declaradas y presencia de test Symfony; no implica equivalencia funcional, CI verde, despliegue o validación productiva. `DIFERIDA` exige razón explícita incluso si existen rutas parciales. `route: null` significa que no se acredita una ruta HTTP equivalente, no que la capacidad no exista.

No se contactan APIs, redes, hosting ni usuarios. No se usan datos reales, claves ni proveedores. Laravel sigue operativo; esta prueba no autoriza GF-MIG-004, migraciones, cutover ni retiro del legado.

<!-- gf-mig-003-parity-json:v1 -->
~~~json
{
  "contract": "GF-MIG-003-FUNCTIONAL-PARITY-OFFLINE-V1",
  "source": "docs/STACK-TRANSITION-SYMFONY.md",
  "capabilities": [
    {
      "domain": "Identidad y tenants",
      "laravel": {
        "route": "/login",
        "definition": "routes/web.php",
        "marker": "Route::get('/login'",
        "parent": null
      },
      "symfony": {
        "route": "/login",
        "definition": "symfony/src/Http/Controller/LoginController.php"
      },
      "evidence": [
        "symfony/tests/php/IdentityLoginTest.php"
      ],
      "status": "RUTA_Y_PRUEBA_EN_CODIGO",
      "deferred_reason": null
    },
    {
      "domain": "Vault e ingesta",
      "laravel": {
        "route": "/organizations/{organizationId}/vault",
        "definition": "routes/web.php",
        "marker": "Route::get('/vault'",
        "parent": "Route::prefix('/organizations/{organizationId}')"
      },
      "symfony": {
        "route": "/api/admin/vault",
        "definition": "symfony/src/Http/Controller/VaultController.php"
      },
      "evidence": [
        "symfony/tests/php/VaultTest.php"
      ],
      "status": "RUTA_Y_PRUEBA_EN_CODIGO",
      "deferred_reason": null
    },
    {
      "domain": "Procesamiento",
      "laravel": {
        "route": null,
        "definition": null,
        "marker": null,
        "parent": null
      },
      "symfony": {
        "route": null,
        "definition": null
      },
      "evidence": [],
      "status": "DIFERIDA",
      "deferred_reason": "Procesamiento por trabajos asíncronos sin par de rutas HTTP ni equivalencia verificada de workers/binarios."
    },
    {
      "domain": "Reglas/Compliance",
      "laravel": {
        "route": "/organizations/{organizationId}/scheduler",
        "definition": "routes/web.php",
        "marker": "Route::get('/scheduler'",
        "parent": "Route::prefix('/organizations/{organizationId}')"
      },
      "symfony": {
        "route": "/api/admin/rules/weekly",
        "definition": "symfony/src/Http/Controller/ContentRuleController.php"
      },
      "evidence": [
        "symfony/tests/php/PublishingAutomationRuleTest.php"
      ],
      "status": "DIFERIDA",
      "deferred_reason": "La regla semanal no acredita toda la elegibilidad de recursos, destinos, texto y enlaces."
    },
    {
      "domain": "Scheduler",
      "laravel": {
        "route": "/organizations/{organizationId}/scheduler",
        "definition": "routes/web.php",
        "marker": "Route::get('/scheduler'",
        "parent": "Route::prefix('/organizations/{organizationId}')"
      },
      "symfony": {
        "route": "/api/admin/schedules",
        "definition": "symfony/src/Http/Controller/ScheduleDraftController.php"
      },
      "evidence": [
        "symfony/tests/php/MvpSchedulingStateTransitionsE2eTest.php"
      ],
      "status": "RUTA_Y_PRUEBA_EN_CODIGO",
      "deferred_reason": null
    },
    {
      "domain": "Distribution",
      "laravel": {
        "route": "/organizations/{organizationId}/distribution",
        "definition": "routes/web.php",
        "marker": "Route::get('/distribution'",
        "parent": "Route::prefix('/organizations/{organizationId}')"
      },
      "symfony": {
        "route": "/api/admin/distribution-authorizations/{assetId}",
        "definition": "symfony/src/Http/Controller/DistributionAuthorizationController.php"
      },
      "evidence": [
        "symfony/tests/php/MvpScheduleToDistributionE2eTest.php"
      ],
      "status": "DIFERIDA",
      "deferred_reason": "Autorización y sandbox no prueban el motor completo ni envíos externos con equivalencia funcional."
    },
    {
      "domain": "Traffic",
      "laravel": {
        "route": "/organizations/{organizationId}/traffic",
        "definition": "routes/web.php",
        "marker": "Route::get('/traffic'",
        "parent": "Route::prefix('/organizations/{organizationId}')"
      },
      "symfony": {
        "route": null,
        "definition": null
      },
      "evidence": [],
      "status": "DIFERIDA",
      "deferred_reason": "No hay par Symfony acreditado de enlaces rastreables, métricas agregadas y CSV."
    },
    {
      "domain": "Finance",
      "laravel": {
        "route": "/organizations/{organizationId}/finance",
        "definition": "routes/web.php",
        "marker": "Route::get('/finance'",
        "parent": "Route::prefix('/organizations/{organizationId}')"
      },
      "symfony": {
        "route": null,
        "definition": null
      },
      "evidence": [],
      "status": "DIFERIDA",
      "deferred_reason": "Ledger comercial y reversas Symfony siguen diferidos, sin ruta equivalente acreditada."
    },
    {
      "domain": "UI/producto",
      "laravel": {
        "route": "/dashboard",
        "definition": "routes/web.php",
        "marker": "Route::get('/dashboard'",
        "parent": null
      },
      "symfony": {
        "route": "/admin",
        "definition": "symfony/src/Http/Controller/AdminController.php"
      },
      "evidence": [
        "symfony/tests/php/PreviewTest.php"
      ],
      "status": "RUTA_Y_PRUEBA_EN_CODIGO",
      "deferred_reason": null
    },
    {
      "domain": "Entrega",
      "laravel": {
        "route": "/health",
        "definition": "routes/web.php",
        "marker": "Route::get('/health'",
        "parent": null
      },
      "symfony": {
        "route": "/health",
        "definition": "symfony/src/Http/Controller/HealthController.php"
      },
      "evidence": [
        "symfony/tests/php/RuntimeReadinessTest.php"
      ],
      "status": "RUTA_Y_PRUEBA_EN_CODIGO",
      "deferred_reason": null
    }
  ]
}
~~~

## Prueba y reversión

Ejecutar `python3 -m unittest tests/test_parity_matrix_executable.py`. Se contrastan cada dominio, ruta Laravel y prefijo, ruta Symfony, pruebas fuente y estados. Los casos negativos fallan con rutas revertidas, estados desconocidos, evidencia eliminada o diferimientos sin razón. La reversión de este PR retira únicamente matriz y tests.

La condición de #350 para GF-MIG-004 continúa pendiente: paridad completa de roles, datos, estados, IDs, históricos, propietarios de escritura, reversión y validación por módulo. Tests de código, deploy y producción son evidencias distintas.
