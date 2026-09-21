# GrindFlow — Último deploy

<p align="center">
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml"><img alt="GrindFlow CI" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg?branch=main"></a>
<a href="https://sonarcloud.io/dashboard?id=pl0n3r_GrindFlow"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_GrindFlow&metric=alert_status"></a>
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-deploy-observer.yml"><img alt="Deploy Observer" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-deploy-observer.yml/badge.svg?branch=main"></a>
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-smoke.yml"><img alt="Production Smoke" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-smoke.yml/badge.svg?branch=main"></a>
</p>

> **Candidato v0.1.69: autorización explícita de distribución S3.** El runtime productivo continúa siendo Laravel en Hostinger; Symfony sigue aislado. Base exacta `main` v0.1.68 `bef8e234154fc4dcecdb7d5cca12ae605911036a`. La autorización es un evento interno, tenant-safe y revocable; no programa ni publica contenido externo.

## Progress convention
- ✅ ~~Completado~~ = verificado; 🚧 Pendiente = en curso; ⛔ bloqueado = dependencia externa.

## Fuentes de verdad
[AGENTS.md](AGENTS.md) · [Spec](docs/GRINDFLOW-SPEC.md) · [Requisitos](docs/REQUIREMENTS.md) · [Transición](docs/STACK-TRANSITION-SYMFONY.md) · [Roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2)

## Estado del deploy
| Señal | Estado | Evidencia |
| --- | --- | --- |
| Version objetivo | 🚧 **v0.1.69** | `config/version.php` |
| Base exacta | ✅ ~~main v0.1.68~~ | `bef8e234154fc4dcecdb7d5cca12ae605911036a` |
| CI del PR | 🚧 Head v0.1.69 por validar | `GrindFlow CI / validate` |
| Sonar | 🚧 Pendiente | SonarCloud PR |
| CodeRabbit | 🚧 Pendiente | PR |
| CI del SHA exacto de main | ✅ ~~v0.1.67 success~~ | run `35604659763` |
| Deploy Observer | ✅ ~~v0.1.67 observado~~ | run `35604659532`; no prueba Symfony remoto ni SHA Hostinger |
| Production Smoke | ⛔ Credencial E2E productiva pendiente | [Issue #1](https://github.com/pl0n3r/GrindFlow/issues/1) |
| Symfony S3 en Hostinger | ⛔ NO desplegado | Solo entorno aislado CI |
| Migraciones | 🚧 Ledger append-only S3 en MariaDB descartable | Producción intacta |

## Huella del cambio
<!-- grindflow:git-delta -->
| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **12** | **+527** | **−33** | **+494** |

## Calidad y entrega
<!-- grindflow:gate-plan -->
| Control | Estado / contrato |
| --- | --- |
| Gates seleccionados | **preflight · fast[contracts] · symfony-preview** |
| Alcance | S3: derivar y mostrar el próximo slot futuro por cada día configurado, con hora IANA y UTC |
| Revisiones | CI/Sonar/CodeRabbit, exact-main y Hostinger independientes |

## Flujo de entrega
```mermaid
flowchart LR
 A["PR + snapshot exacto"] --> P["preflight"]
 P --> F["fast contracts"]
 F --> V["validate"]
 A --> S["Sonar"]
 A --> C["CodeRabbit"]
 V --> M["Squash merge"]
 M --> X["CI exact-main"]
 X --> O["Observer release"]
 O --> T["Smoke autenticado separado"]
```

## Qué se hizo
- Nuevo ledger append-only de eventos `grant/revoke` por recurso y organización, con actor y fecha UTC.
- Admin/Studio pueden autorizar o revocar con CSRF dedicado y revalidación tenant/rol dentro de la transacción; Editor/Model permanecen sin permiso.
- El preview S3 refleja la autorización explícita, pero conserva `can_publish=false`: autorizar no crea schedules, jobs ni llamadas a proveedores.
- El panel móvil permite la decisión explícita y muestra que la autorización interna no acredita derechos ni publicación.

## Archivos modificados en este deploy
Inventario de solo el candidato actual; no prueba despliegue Symfony en Hostinger.
- `README.md`
- `config/version.php`
- `docs/REQUIREMENTS.md`
- `symfony/frontend/admin/AdminApp.tsx`
- `symfony/frontend/admin/WeeklyPlannerPanel.tsx`
- `symfony/frontend/admin/admin.css`
- `symfony/migrations/Version20260921135500.php`
- `symfony/src/Http/Controller/AdminContextController.php`
- `symfony/src/Http/Controller/ContentRuleController.php`
- `symfony/src/Http/Controller/DistributionAuthorizationController.php`
- `symfony/src/Identity/Application/MembershipContext.php`
- `symfony/tests/php/DistributionAuthorizationTest.php`

## Validación
- CI/Sonar/CodeRabbit del candidato v0.1.69 por verificar; la base v0.1.68 está fusionada.
- Sin checkout local; GitHub Actions valida Symfony/MariaDB, TypeScript/Vite y Chromium. Producción intacta.

## Qué sigue
[Roadmap canónico #2](https://github.com/pl0n3r/GrindFlow/issues/2)

| Lane | Trabajo | Estado |
| --- | --- | --- |
| **NOW** | 🚧 Validar autorización explícita S3 v0.1.69 | 🚧 CI y revisión |
| **NEXT** | 🚧 Persistencia de schedules Symfony sobre contratos S3 | 🚧 Sin publicación externa |
| **LATER** | 🚧 Scheduler Symfony + distribución autorizada + Traffic | 🚧 S3–S5 |
| **BLOCKED / EXTERNAL** | ⛔ Cutover sin paridad/datos migrados; Smoke sin credencial | ⛔ Dependencia externa |

## Panorama general pendiente
| Lane | Frente | Estado |
| --- | --- | --- |
| **DONE** | ✅ ~~Dashboard Laravel v0.1.30~~ | ✅ ~~Vault Symfony clasificación v0.1.63~~ |
| **NOW** | 🚧 Autorización explícita S3 v0.1.69 | 🚧 CI y revisión |
| **NEXT** | 🚧 Scheduler persistido tenant-safe | 🚧 S3 |
| **LATER** | 🚧 Paridad del monolito modular | 🚧 S3–S5 |
| **BLOCKED / EXTERNAL** | ⛔ Sin cutover Symfony | ⛔ Sin credencial Smoke |
