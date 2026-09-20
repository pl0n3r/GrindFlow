# GrindFlow — Último deploy

<p align="center">
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml"><img alt="GrindFlow CI" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg?branch=main"></a>
<a href="https://sonarcloud.io/dashboard?id=pl0n3r_GrindFlow"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_GrindFlow&metric=alert_status"></a>
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-deploy-observer.yml"><img alt="Deploy Observer" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-deploy-observer.yml/badge.svg?branch=main"></a>
<a href="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-smoke.yml"><img alt="Production Smoke" src="https://github.com/pl0n3r/GrindFlow/actions/workflows/production-smoke.yml/badge.svg?branch=main"></a>
</p>

> **Snapshot v0.1.30: solo el deploy actual.** Base `main` v0.1.29 `b72a5947e101e44254c79235d0d468572349abb4`. Entrega pequeña y visible: programaciones cuya fecha ya pasó, todavía en estado programado, con enlace de revisión. No inferir fallos de entrega del estado de programación. Laravel es runtime actual; Symfony S0 continúa aislado. **CI ≠ Hostinger**.

## Progress convention
- ✅ ~~Completado~~ = verificado; 🚧 Pendiente = en curso; ⛔ bloqueado = dependencia externa.

## Fuentes de verdad
[AGENTS.md](AGENTS.md) · [Spec](docs/GRINDFLOW-SPEC.md) · [Requisitos](docs/REQUIREMENTS.md) · [Transición](docs/STACK-TRANSITION-SYMFONY.md) · [Roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2)

## Estado del deploy
| Señal | Estado | Evidencia |
| --- | --- | --- |
| Version objetivo | 🚧 **v0.1.30** | `config/version.php` |
| Base exacta | ✅ ~~main v0.1.29~~ | `b72a5947e101e44254c79235d0d468572349abb4` |
| CI del PR | 🚧 Head final pendiente | `GrindFlow CI / validate` |
| Sonar | 🚧 Pendiente | SonarCloud PR |
| CodeRabbit | 🚧 Revisión por comprobar | PR |
| CI del SHA exacto de main | 🚧 Después del merge | No inferir del PR |
| Deploy Observer | ⛔ Release remoto no observado | Hostinger independiente |
| Production Smoke | ⛔ Credencial E2E de solo lectura pendiente | [Issue #1](https://github.com/pl0n3r/GrindFlow/issues/1) |
| Producción v0.1.30 | ⛔ Sin verificar | CI ≠ Hostinger |
| Migraciones | ✅ ~~Sin cambios de esquema~~ | Solo lecturas tenant-safe |

## Huella del cambio
<!-- grindflow:git-delta -->
| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **7** | **+119** | **−25** | **+94** |

## Calidad y entrega
<!-- grindflow:gate-plan -->
| Control | Estado / contrato |
| --- | --- |
| Gates seleccionados | **preflight · fast[contracts] · php-quality · PHPUnit · MariaDB · browser · real-stack** |
| Alcance | Agenda de fechas pasadas, filtro tenant y UTC, PHP/Chromium |
| Revisiones | CI/Sonar/CodeRabbit y exact-main independientes |

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
- Dashboard ofrece un bloque de últimas cinco fechas pasadas aún programadas, nombre, destino, fecha UTC y vínculo a su agenda.
- Reutiliza la consulta de próximas publicaciones con parámetro de fecha, orden descendente para fechas pasadas y joins restringidos a organizaciones visibles. No confunde estado programado con fracaso de entrega.
- Footer y CSS toman `config/version.php`: **0.1.29 → 0.1.30**. Sin migraciones ni cambios productivos.

## Archivos modificados en este deploy
- `README.md`
- `app/Http/Controllers/DashboardController.php`
- `config/version.php`
- `public/css/grindflow.css`
- `resources/views/dashboard.blade.php`
- `scripts/browser-smoke.sh`
- `tests/Feature/OrganizationVisibilityTest.php`

## Validación
- CI/PR y exact-main, Observer Hostinger y smoke son evidencias distintas.
- Un registro con fecha anterior pendiente de programación no certifica fallos del proveedor.

## Qué sigue
| Lane | Trabajo |
| --- | --- |
| **NOW** | 🚧 Verificar agenda vencida v0.1.30, [roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2) |
| **NEXT** | 🚧 S1 identidad y tenant Symfony en entregas pequeñas |
| **LATER** | 🚧 Vault móvil → reglas → distribución autorizada → piloto |
| **BLOCKED / EXTERNAL** | ⛔ Observación Hostinger; credencial smoke pendiente |

## Panorama general pendiente
| Lane | Frente | Estado |
| --- | --- | --- |
| **DONE** | ✅ ~~Home SaaS, footers y dashboard~~ | ✅ ~~Agenda futura v0.1.29~~ |
| **NOW** | 🚧 Fechas pasadas v0.1.30 | 🚧 CI y Hostinger |
| **NEXT** | 🚧 Identidad Symfony S1 | 🚧 Por portar |
| **LATER** | 🚧 Automatización completa | 🚧 S2–S5 |
| **BLOCKED / EXTERNAL** | ⛔ Producción v0.1.30 | ⛔ Sin evidencia remota |
