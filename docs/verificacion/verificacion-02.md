# Verificación de cobertura, lote 02/4 — GrindFlow #946

**Alcance:** 25 filas, inspección local sin producción ni datos reales. **Base de lectura:** main@a4461bc9066feca598116889bdbb62c0f0da29a2. **Reserva V3:** 20fd1cd8-e913-4a7b-b535-863c20341a54. La identidad HEAD de la PR se acreditará solo mediante los checks del PR, no con este documento.

Regla: **PARCIAL** indica una regresión local trazable, no implementación integral; **EXTERNO** requiere sistema/entorno observado; **DECISION** requiere decisión del propietario o piloto; **DOCUMENTADO** es una definición de arquitectura; **BRECHA** mantiene el estado SIN_COBERTURA en la matriz hasta comprobar el circuito de navegador. Todas las filas conservan **NO_ACREDITADO** para CI exact-HEAD, deploy y producción hasta recibir evidencia independiente. Este documento no modifica `docs/matriz-cobertura.md`.

| ID | Diagnóstico | Fuentes verificables | Brecha pendiente |
| --- | --- | --- | --- |
| REQ:GF-FR-004D | PARCIAL | `docs/REQUIREMENTS.md`; `tests/test_brechas_grindflow_02.py` | calendario: SQL paginado, filtros y destinos deshabilitados; falta E2E independiente |
| REQ:GF-FR-007B | PARCIAL | `docs/REQUIREMENTS.md`; `tests/test_brechas_grindflow_04.py` | ledger/CSV multimoneda y aislamiento; falta observación integral |
| REQ:GF-FR-006D | PARCIAL | `docs/REQUIREMENTS.md`; `tests/test_brechas_grindflow_03.py` | enlaces Traffic >100, edición y filtros; falta ejecución completa |
| REQ:GF-FR-004E | PARCIAL | `docs/REQUIREMENTS.md`; `tests/test_brechas_grindflow_02.py` | buscadores profundos y old IDs; falta cobertura final de navegador |
| REQ:GF-NFR-006 | BRECHA | `docs/REQUIREMENTS.md`; `tests/test_cross_module_security_e2e_contract.py` | el contrato HTTP/browser existe; confirmar ejecución Chromium end-to-end con receipt exact-SHA y resolver hoja existente #636 |
| REQ:GF-FR-005D | PARCIAL | `docs/REQUIREMENTS.md`; `tests/test_brechas_grindflow_02.py` | entregas paginadas por tenant; falta observación desplegada |
| STACK:GF-ARCH-001 | EXTERNO | `docs/STACK-TRANSITION-SYMFONY.md`; `tests/test_cobertura_grindflow_04.py` | paridad Symfony/Hostinger y PHP observado aún no acreditados |
| STACK:GF-UX-003 | PARCIAL | `docs/STACK-TRANSITION-SYMFONY.md`; `tests/test_brechas_grindflow_17.py` | nav React/preview y CSS; falta recepción Chromium conjunta exact-SHA |
| STACK:GF-UX-002 | PARCIAL | `docs/STACK-TRANSITION-SYMFONY.md`; `tests/test_cobertura_grindflow_04.py` | cabecera home/login/selector; falta E2E completo |
| STACK:GF-ARCH-002 | EXTERNO | `docs/STACK-TRANSITION-SYMFONY.md`; `tests/test_data_schema_parity.py` | paridad de esquema y tenencia real reversible pendiente |
| STACK:GF-ARCH-003 | EXTERNO | `docs/STACK-TRANSITION-SYMFONY.md`; `tests/test_cobertura_grindflow_04.py` | coexistencia, rollback y smoke exactos sin recibo terminal |
| STACK:GF-OPS-012 | PARCIAL | `docs/STACK-TRANSITION-SYMFONY.md`; `tests/test_cobertura_grindflow_04.py` | policy deprecations CI: comprobar reporte vendor y HEAD final |
| STACK:GF-OPS-013 | PARCIAL | `docs/STACK-TRANSITION-SYMFONY.md`; `tests/test_cobertura_grindflow_04.py` | pins oficiales Node 24 y sintaxis Ruby; falta acreditar todos los jobs |
| STACK:GF-OPS-014 | PARCIAL | `docs/STACK-TRANSITION-SYMFONY.md`; `tests/test_cobertura_grindflow_04.py` | checkout persist-credentials false; falta corrida completa con jobs |
| STACK:GF-FR-008 | EXTERNO | `docs/STACK-TRANSITION-SYMFONY.md`; `tests/test_cobertura_grindflow_04.py` | piloto integral autenticado exige evidencia autorizada y negativa tenant |
| STACK:GF-FR-009 | DECISION | `docs/STACK-TRANSITION-SYMFONY.md`; `tests/test_cobertura_grindflow_04.py` | evaluación comercial humana de cinco participantes no ejecutada |
| STACK:GF-FR-010 | PARCIAL | `docs/STACK-TRANSITION-SYMFONY.md`; `tests/test_cobertura_grindflow_04.py` | notas Vault CRUD privado y tenant; falta DB/HTTP integral |
| STACK:GF-FR-011 | PARCIAL | `docs/STACK-TRANSITION-SYMFONY.md`; `tests/test_cobertura_grindflow_04.py` | clasificación y filtro Vault; faltan negativos de sesión y rol |
| STACK:GF-UX-001 | PARCIAL | `docs/STACK-TRANSITION-SYMFONY.md`; `tests/test_cobertura_grindflow_04.py` | shell Laravel responsive distinto al Symfony; navegador pendiente |
| SPEC:005 | EXTERNO | `docs/GRINDFLOW-SPEC.md`; `tests/test_brechas_grindflow_06.py` | escalado multisegmento requiere experiencia real de varios perfiles |
| SPEC:007 | DECISION | `docs/GRINDFLOW-SPEC.md`; `tests/test_brechas_grindflow_06.py` | cobros y precios precisan decisión comercial y proveedor autorizado |
| SPEC:008 | DECISION | `docs/GRINDFLOW-SPEC.md` | piloto de cinco creadoras necesita consentimiento/operación real; no simular |
| SPEC:009 | DECISION | `docs/GRINDFLOW-SPEC.md` | 30 días y hasta dos redes son propuesta, no política aprobada |
| SPEC:010 | DOCUMENTADO | `docs/GRINDFLOW-SPEC.md` | arquitectura objetivo es decisión, no release verificado |
| SPEC:011 | DOCUMENTADO | `docs/GRINDFLOW-SPEC.md` | monolito Symfony es objetivo; cutover y paridad sin atestar |

## Ejecución y límites

- Suite de este lote: `python3 -m unittest tests/test_verificacion_grindflow_02.py -v` (comprueba 25 claves únicas, estado conservador, pruebas rastreables, mutaciones de fuente y rechazo de recibos falsos). Los tests preexistentes se citan como evidencia **declarada** y deben ejecutarse por sus gates correspondientes; no se afirma haberlos ejecutado mediante este inventario.
- La fila `REQ:GF-NFR-006` ya enlaza una hoja específica [#636](https://github.com/pl0n3r/GrindFlow/issues/636); `tests/test_cross_module_security_e2e_contract.py` y `scripts/browser-workflow.sh` ofrecen pruebas parciales. No abrir un duplicado ni declarar `CUBIERTO` sin un recibo real del job Chromium y comprobación de aislamiento/CSRF.
- Los requisitos comerciales `SPEC:007/008/009` y `STACK:GF-FR-009` requieren operación/decisiones humanas: los fixtures offline no los hacen verdaderos.
- Gates de aceptación: `Factory CI reusable / Validar` exacto al HEAD del PR, revisión de seguridad, CodeRabbit solo FINAL con CI verde y reserva V3 vigente. Sin CI/PR final, resultado UNKNOWN; no go-live, secretos, cobros, proveedor ni despliegue.

## Reversión

Revertir el squash de los dos archivos nuevos. No afecta funciones ni datos de los productos.
