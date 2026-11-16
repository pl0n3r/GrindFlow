# GrindFlow #948 — verificación de cobertura, lote 04/04

**Alcance:** 23 filas, 9 SPEC `NO_VERIFICABLE` y 14 RM `SIN_COBERTURA`. Reserva V3: `d47164ad-b5b8-4eca-9a9f-b58662e591df` (`trabajo/issue-948`). Evidencia local de `main@919e49be6948c0c603622982838231de56a9e1f3`; el CI del PR acredita por separado su HEAD definitivo.

## Semántica y criterio de no promoción

- Un testigo existente acredita un **subcontrato offline**, no aceptación integral ni operación productiva. No se cambia `docs/matriz-cobertura.md`, y ninguna fila queda acreditada por la mera existencia de este reporte.
- `EXTERNO`: falta recibo autenticado de navegador, release o runtime real. `PARCIAL`: existen aserciones locales con brecha adicional. `HOJA_PARCIAL`: existe leaf canónico con tests locales, pero no prueba el roadmap completo. `BRECHA_PENDIENTE`: la hoja canónica existe pero su testigo no está en `main`.
- Las 14 filas RM conservan `SIN_COBERTURA` **hasta que la hoja de consolidación**, con evidencia causal suficiente, determine cuáles tienen acreditación integral. #636–#644 son hojas canónicas existentes; no crear duplicados.
- La prueba de #640 para RM-348 no está presente en `main`; no se inventa una aserción. Los requisitos de la decisión comercial y retirada de Laravel permanecen sujetos a su propia autoridad.

## Contraste por fila

| ID | Estado matriz | Dictamen | Fuente o hoja existente | Testigo local | Brecha específica |
| --- | --- | --- | --- | --- | --- |
| SPEC:038 | NO_VERIFICABLE | PARCIAL | docs/GRINDFLOW-SPEC.md (L451) | tests/test_brechas_grindflow_12.py::BrechasGrindflow12Tests.test_contrato_principal | El buscador y filtros se ensayan en fuentes locales; faltan sesión Scheduler autenticada, consulta real y recorrido paginado. |
| SPEC:039 | NO_VERIFICABLE | EXTERNO | docs/GRINDFLOW-SPEC.md (L470) | tests/test_brechas_grindflow_13.py::BrechasGrindflow13Tests.test_contrato_principal | El script de navegador define un contrato descartable; falta ejecución autenticada real y recibo exact-SHA del workflow. |
| SPEC:040 | NO_VERIFICABLE | PARCIAL | docs/GRINDFLOW-SPEC.md (L495) | tests/test_brechas_grindflow_13.py::BrechasGrindflow13Tests.test_contrato_principal | La prueba paginada de Distribution es sintética; faltan observación SQL y aislamiento real en entorno autorizado. |
| SPEC:041 | NO_VERIFICABLE | EXTERNO | docs/GRINDFLOW-SPEC.md (L501) | tests/test_brechas_grindflow_13.py::BrechasGrindflow13Tests.test_contrato_principal | El marcador release-only no prueba commit de hosting; se exige observación independiente y regresión real-stack. |
| SPEC:042 | NO_VERIFICABLE | PARCIAL | docs/GRINDFLOW-SPEC.md (L515) | tests/test_brechas_grindflow_13.py::BrechasGrindflow13Tests.test_contrato_principal | Elegibilidad semanal se verifica con fixtures; faltan permisos y datos Vault en sesión autenticada. |
| SPEC:043 | NO_VERIFICABLE | PARCIAL | docs/GRINDFLOW-SPEC.md (L539) | tests/test_brechas_grindflow_13.py::BrechasGrindflow13Tests.test_contrato_principal | Los slots derivados se contrastan localmente; falta evidencia de aislamiento y UTC del runtime efectivo. |
| SPEC:044 | NO_VERIFICABLE | EXTERNO | docs/GRINDFLOW-SPEC.md (L557) | tests/test_brechas_grindflow_14.py::BrechasGrindflow14Tests.test_contrato_principal | El runtime puede responder degradado; falta observar extensiones, MariaDB y contrato en despliegue real. |
| SPEC:045 | NO_VERIFICABLE | PARCIAL | docs/GRINDFLOW-SPEC.md (L576) | tests/test_brechas_grindflow_14.py::BrechasGrindflow14Tests.test_contrato_principal | Relaciones de colecciones y variantes tienen contrato local; faltan migración y transacciones reales verificadas. |
| SPEC:046 | NO_VERIFICABLE | PARCIAL | docs/GRINDFLOW-SPEC.md (L582) | tests/test_brechas_grindflow_14.py::BrechasGrindflow14Tests.test_contrato_principal | Ventanas UTC y exclusión se prueban offline; faltan transacciones reales y autorización de despliegue. |
| RM-342 | SIN_COBERTURA | HOJA_PARCIAL | GrindFlow#342 / #636 | tests/test_cross_module_security_e2e_contract.py::CrossModuleSecurityE2ETests.test_guard_rejects_missing_cross_tenant_or_csrf_assertion | Falta E2E autenticado integral, no solo fixtures de aislamiento y CSRF. |
| RM-343 | SIN_COBERTURA | HOJA_PARCIAL | GrindFlow#343 / #637 | tests/test_operations_storage_readiness_contract.py::OperationsStorageReadinessTests.test_readonly_health_and_storage_contract | Falta observación operativa de 5xx, jobs y diagnósticos, con recibos sanitizados. |
| RM-344 | SIN_COBERTURA | HOJA_PARCIAL | GrindFlow#344 / #638 | tests/test_transversal_accessibility_contract.py::TransversalAccessibilityTests.test_negative_mutations_fail_closed | Falta recorrido responsive, teclado y lector en navegador de todas las pantallas. |
| RM-345 | SIN_COBERTURA | HOJA_PARCIAL | GrindFlow#345 / #637 | tests/test_operations_storage_readiness_contract.py::OperationsStorageReadinessTests.test_readonly_health_and_storage_contract | Falta validar storage/CORS/binarios reales del hosting con autorización independiente. |
| RM-346 | SIN_COBERTURA | HOJA_PARCIAL | GrindFlow#346 / #636 | tests/test_cross_module_security_e2e_contract.py::CrossModuleSecurityE2ETests.test_tenant_csrf_permissions_cases | Falta auditoría real integral de tenant, CSRF, cifrado y uploads por módulo. |
| RM-347 | SIN_COBERTURA | HOJA_PARCIAL | GrindFlow#347 / #639 | tests/test_connector_retry_quota_contract.py::ConnectorRetryQuotaTests.test_negative_mutations_reject_weakened_retry_guards | Faltan cuotas y reintentos observados en proveedores autorizados; no iniciar conectores. |
| RM-348 | SIN_COBERTURA | BRECHA_PENDIENTE | GrindFlow#348 / #640 | NO_ACREDITADO | La hoja #640 sigue OPEN/planificada; su testigo de equipos/destinos no existe aún en main. |
| RM-350 | SIN_COBERTURA | HOJA_PARCIAL | GrindFlow#350 / #641 | tests/test_migration_parity_exit_gate.py::MigrationParityExitGateTests.test_fake_full_parity_does_not_bypass_real_matrix_validator | El gate de paridad offline rechaza retiro sin recibos; faltan paridad y rollback reales autorizados. |
| RM-351 | SIN_COBERTURA | HOJA_PARCIAL | GrindFlow#351 / #642 | tests/test_prelaunch_business_readiness_contract.py::PrelaunchBusinessReadinessTests.test_missing_market_or_support_evidence_is_not_ready | Faltan costes, soporte, SLA y onboarding aprobados por producto. |
| RM-352 | SIN_COBERTURA | HOJA_PARCIAL | GrindFlow#352 / #643 | tests/test_commercial_decisions_fail_closed.py::CommercialDecisionsFailClosedTests.test_no_trial_or_price_without_owner_gate | La prueba 30 días/dos redes requiere piloto concluido y decisión comercial explícita. |
| RM-353 | SIN_COBERTURA | HOJA_PARCIAL | GrindFlow#353 / #643 | tests/test_commercial_decisions_fail_closed.py::CommercialDecisionsFailClosedTests.test_no_trial_or_price_without_owner_gate | Precios y límites carecen de decisión humana y evidencia comercial aprobada. |
| RM-355 | SIN_COBERTURA | HOJA_PARCIAL | GrindFlow#355 / #642 | tests/test_prelaunch_business_readiness_contract.py::PrelaunchBusinessReadinessTests.test_segments_and_modalities_are_not_commercial_authorization | Segmentos/modalidades y propuesta de valor no cuentan con validación de mercado. |
| RM-356 | SIN_COBERTURA | HOJA_PARCIAL | GrindFlow#356 / #642 | tests/test_prelaunch_business_readiness_contract.py::PrelaunchBusinessReadinessTests.test_p0_p1_or_missing_production_identity_vetoes_release | La salida 1.0.0 requiere gate comercial, técnico y operativo auténtico sin pendientes. |
| RM-358 | SIN_COBERTURA | HOJA_PARCIAL | GrindFlow#358 / #644 | tests/test_s7_postmvp_scope_gate.py::PostMvpScopeGateTests.test_future_scope_requires_explicit_product_decision | S7 es post-MVP; falta decisión explícita y prueba de capacidad, no iniciar integraciones. |

## Tests, fronteras y reversión

- `tests/test_verificacion_grindflow_04.py` verifica 23 IDs únicos en matriz, resumen exacto y estados conservadores, fuente SPEC por sección/ancla, hoja existente, método testigo con aserción real, y registra brecha por fila.
- Mutaciones negativas de ID, estado, duplicado, origen, ancla, testigo o acreditación productiva inventada deben fallar; se ejecutan testigos positivos y negativos ya existentes con datos sintéticos.
- Evidencia de runtime, navegador, providers, cuota, S7, precios, backups, go-live y Hostinger = `NO_ACREDITADO` hasta recibos independientes. No acceder a datos personales, red ni proveedores.
- **AC-03:** verificar `Factory CI reusable / Validar` terminal `success` y checks de seguridad sobre el SHA exacto del PR; CodeRabbit solo una solicitud FINAL con CI verde. Revert del squash devuelve los dos archivos sin tocar aplicación o estado externo.
