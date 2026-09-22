# GrindFlow — Último deploy

<p align="center">
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml"><img alt="GrindFlow CI" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg?branch=main"></a>
<a href="https://sonarcloud.io/dashboard?id=pl0n3r_GrindFlow"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_GrindFlow&metric=alert_status"></a>
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-deploy-observer.yml"><img alt="Deploy Observer" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-deploy-observer.yml/badge.svg?branch=main"></a>
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-smoke.yml"><img alt="Production Smoke" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-smoke.yml/badge.svg?branch=main"></a>
</p>

> **Candidato v0.1.87: readiness segura del runtime Symfony sin fingerprint sensible.** Base exacta `main` v0.1.86 `457312fcb68d743f0d460b34e25bf458ad452cee`; no cambia el runtime Laravel productivo ni ejecuta cutover.

## Progress convention
- ✅ ~~Completado~~ = verificado; 🚧 Pendiente = en curso; ⛔ bloqueado = dependencia externa.

## Fuentes de verdad
[AGENTS.md](AGENTS.md) · [Spec](docs/GRINDFLOW-SPEC.md) · [Requisitos](docs/REQUIREMENTS.md) · [Transición](docs/STACK-TRANSITION-SYMFONY.md) · [Roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2)

## Estado del deploy
| Señal | Estado | Evidencia |
| --- | --- | --- |
| Version objetivo | 🚧 **v0.1.87** | `config/version.php`; no publicada |
| Base exacta | ✅ ~~main v0.1.86~~ | `457312fcb68d743f0d460b34e25bf458ad452cee` |
| CI / Sonar / CodeRabbit del PR | 🚧 Pendiente | Revalidar HEAD final |
| CI del SHA exacto de main | 🚧 Sin run observado aún | Validación post-merge v0.1.86 separada |
| Deploy Observer | 🚧 Sin run observado aún | No inferir checkout remoto |
| Production Smoke | ⛔ Login E2E no validado | #73 sigue independiente |
| Symfony en Hostinger | ⛔ NO desplegado | `symfony-preview` es aislado |
| Migraciones | ✅ ~~Sin cambio de esquema~~ | Ninguna migración productiva |

## Huella del cambio
<!-- grindflow:git-delta -->
| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **9** | **+0** | **−0** | **+0** |

## Calidad y entrega
<!-- grindflow:gate-plan -->
| Control | Estado / contrato |
| --- | --- |
| Gates seleccionados | **preflight · fast[contracts] · symfony-preview** |
| Gate agregador obligatorio | **validate**: todos los seleccionados; Sonar y CodeRabbit aparte |
| Alcance | Readiness pública del proceso Symfony; sin fingerprint ni acceso a producción |
| Revisiones | CI/Sonar/CodeRabbit HEAD, exact-main, Observer y Smoke separados |

## Flujo de entrega
```mermaid
flowchart LR
 A["PR + snapshot exacto"] --> P["preflight"]
 P --> F["fast contracts"]
 P --> Y["Symfony preview"]
 F --> V["validate"]
 Y --> V
 A --> S["Sonar"]
 A --> C["CodeRabbit"]
 V --> M["Squash merge"]
 S --> M
 C --> M
 M --> X["CI exact-main"]
 X --> O["Observer release"]
 O --> T["Smoke separado"]
```

## Qué se hizo
- Nuevo contrato `symfony-mariadb-v1`: PHP >= 8.3 y < 9.0, `ctype`, `iconv`, PDO y `pdo_mysql`; falla cerrado si falta una capacidad.
- `GET /health` añade únicamente `runtime.compatible` y el identificador estable del contrato. Un runtime incompatible responde 503/`degraded`; uno compatible 200/`ok`.
- La respuesta nunca publica versión exacta de PHP, SAPI, inventario de extensiones, URL de base de datos ni SHA de deploy.
- PHPUnit cubre límites de PHP y extensión ausente; el smoke HTTP real comprueba la forma pública. No se toca Hostinger, credenciales, datos ni migraciones.

## Archivos modificados en este deploy
Inventario exacto de la candidata:
- `README.md`
- `config/version.php`
- `docs/GRINDFLOW-SPEC.md`
- `docs/REQUIREMENTS.md`
- `symfony/src/Http/Controller/HealthController.php`
- `symfony/src/Infrastructure/Runtime/RuntimeReadiness.php`
- `symfony/tests/contract/smoke.sh`
- `symfony/tests/php/PreviewTest.php`
- `symfony/tests/php/RuntimeReadinessTest.php`

## Validación
- La rama debe pasar `preflight`, `fast[contracts]`, `symfony-preview`, `validate`, Sonar y revisión final CodeRabbit sobre el mismo HEAD.
- Readiness significa compatibilidad del proceso Symfony ejecutado; **no** acredita MariaDB productiva, migraciones, SHA Hostinger, cutover ni login productivo.

## Qué sigue
[Roadmap canónico #2](https://github.com/pl0n3r/GrindFlow/issues/2)

| Lane | Trabajo | Estado |
| --- | --- | --- |
| **NOW** | 🚧 Validar readiness segura Symfony | 🚧 v0.1.87 candidata |
| **NEXT** | 🚧 Inventario/paridad de datos antes de cutover | 🚧 GF-ARCH-002 |
| **LATER** | 🚧 Conmutación Symfony por módulo | 🚧 Sin deploy |
| **BLOCKED / EXTERNAL** | ⛔ Resolver login E2E productivo | ⛔ #73 |

## Panorama general pendiente
| Lane | Frente | Estado |
| --- | --- | --- |
| **DONE** | ✅ ~~v0.1.86 fusionada~~ | ✅ ~~PR #85 CI/Sonar/CodeRabbit exact-HEAD~~ |
| **NOW** | 🚧 Runtime readiness sin fingerprint | 🚧 v0.1.87 |
| **NEXT** | 🚧 Paridad/propietario de escritura por módulo | 🚧 Sin cutover |
| **LATER** | 🚧 Symfony en Hostinger | 🚧 No desplegado |
| **BLOCKED / EXTERNAL** | ⛔ Smoke autenticado Laravel | ⛔ #73 |
